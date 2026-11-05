<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Integracoes\WhatsApp\SimuladorWhatsApp;
use App\Jobs\EnviarMensagemWhatsApp;
use App\Models\Cliente;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Models\WhatsappConversa;
use App\Models\WhatsappMensagem;
use App\Models\WhatsappNumero;
use App\Services\Entradas;
use App\Services\WhatsApp\Atrasos;
use App\Services\WhatsApp\Atendimento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Central de WhatsApp (RF23): critérios de aceite da especificação docs/especificacao-central-whatsapp.md. */
class CentralWhatsAppTest extends TestCase
{
    private Unidade $u1;
    private Unidade $u2;
    private User $dono;
    private User $rec1;
    private User $rec2;
    private User $ger1;
    private User $mec1;
    private WhatsappNumero $central;
    private WhatsappNumero $num1;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado', 'oficina.whatsapp.webhook_segredo' => 'segredo-teste-wa']);
        Storage::fake('local');
        Http::preventStrayRequests(); // nenhuma chamada de rede real
        $this->u1 = Unidade::factory()->create(['nome' => 'Unidade Norte']);
        $this->u2 = Unidade::factory()->create(['nome' => 'Unidade Sul']);
        $this->dono = $this->usuario(Papel::Proprietario);
        $this->rec1 = $this->usuario(Papel::Recepcao, $this->u1);
        $this->rec2 = $this->usuario(Papel::Recepcao, $this->u2);
        $this->ger1 = $this->usuario(Papel::Gerente, $this->u1);
        $this->mec1 = $this->usuario(Papel::Mecanico, $this->u1);
        $this->central = $this->numero(null, '6130000100');
        $this->num1 = $this->numero($this->u1, '6130000101');
    }

    private function numero(?Unidade $u, string $tel, array $extra = []): WhatsappNumero
    {
        return WhatsappNumero::create($extra + ['nome' => 'WA '.$tel, 'telefone' => $tel, 'unidade_id' => $u?->id, 'provedor' => 'simulado',
            'minutos_alerta' => 15, 'criado_por' => $this->dono->id, 'modelos' => [['nome' => 'retomar_contato', 'idioma' => 'pt_BR', 'descricao' => 'Retomar']]]);
    }

    private function postSim(array $payload, ?string $assinatura = null): TestResponse
    {
        $corpo = json_encode($payload);

        return $this->call('POST', '/webhooks/whatsapp/simulado', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ASSINATURA' => $assinatura ?? SimuladorWhatsApp::assinar($corpo)], $corpo);
    }

    private function recebe(WhatsappNumero $n, string $de, string $texto, ?string $id = null, array $extra = []): TestResponse
    {
        return $this->postSim(['numero' => $n->telefone, 'eventos' => [$extra + ['tipo' => 'mensagem', 'id' => $id ?? 'IN-'.uniqid(), 'de' => '55'.$de, 'nome' => 'Contato',
            'conteudo' => ['tipo' => 'texto', 'texto' => $texto], 'timestamp' => now()->getTimestamp()]]]);
    }

    private function conversa(WhatsappNumero $n, string $tel): WhatsappConversa
    {
        return WhatsappConversa::where('numero_id', $n->id)->where('contato_telefone', $tel)->firstOrFail();
    }

    private function ordem(Unidade $u, User $rec, string $tel, string $placa = 'ABC1D23'): Ordem
    {
        return app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'Mariana Costa', 'telefone' => $tel, 'placa' => $placa, 'motivo' => 'Revisão']);
    }

    // ---------------- Recebimento: simulador ----------------

    public function test_webhook_simulador_cria_conversa_vincula_cliente_e_e_idempotente(): void
    {
        $o = $this->ordem($this->u1, $this->rec1, '61991234567');
        $this->recebe($this->num1, '61991234567', 'O carro ficou pronto?', 'IN-1')->assertOk()->assertJson(['novos' => 1]);
        $c = $this->conversa($this->num1, '61991234567');
        $this->assertSame([$this->u1->id, $o->cliente_id, 'nova'], [$c->unidade_id, $c->cliente_id, $c->situacao]);
        $this->assertNotNull($c->aguardando_desde);
        $m = WhatsappMensagem::where('conversa_id', $c->id)->sole();
        $this->assertSame(['entrada', 'cliente', 'recebida', 'O carro ficou pronto?'], [$m->direcao, $m->origem, $m->status, $m->texto]);
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_recebida', 'entidade_tipo' => 'whatsapp_conversa', 'entidade_id' => $c->id]);

        // Repetição do mesmo id do provedor não duplica (nem evento nem mensagem).
        $this->recebe($this->num1, '61991234567', 'O carro ficou pronto?', 'IN-1')->assertOk()->assertJson(['novos' => 0]);
        $this->assertSame(1, WhatsappMensagem::count());
        $this->assertSame(1, DB::table('integration_events')->where('provedor', 'whatsapp_simulado')->count());

        // Segunda mensagem do mesmo contato: mesma conversa.
        $this->recebe($this->num1, '61991234567', 'Alô?', 'IN-2');
        $this->assertSame(1, WhatsappConversa::count());
        $this->assertSame(2, WhatsappMensagem::count());
    }

    public function test_assinatura_invalida_e_recusada_sem_gravar(): void
    {
        $payload = ['numero' => $this->num1->telefone, 'eventos' => [['tipo' => 'mensagem', 'id' => 'X1', 'de' => '5561991234567', 'conteudo' => ['tipo' => 'texto', 'texto' => 'oi']]]];
        $this->postSim($payload, 'assinatura-falsa')->assertStatus(401);
        $this->postSim($payload, '')->assertStatus(401);
        config(['oficina.whatsapp.webhook_segredo' => '']);
        $this->postSim($payload, hash_hmac('sha256', json_encode($payload), ''))->assertStatus(401);
        $this->assertSame(0, DB::table('integration_events')->count());
        $this->assertSame(0, WhatsappConversa::count());
    }

    public function test_webhook_do_simulador_nao_existe_em_modo_real_e_meta_nao_existe_em_simulacao(): void
    {
        $this->recebe($this->num1, '61991234567', 'oi')->assertOk();
        $this->call('POST', '/webhooks/whatsapp/meta', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();
        config(['oficina.integracoes' => 'real']);
        $this->recebe($this->num1, '61991234567', 'oi')->assertNotFound();
    }

    public function test_variacao_do_nono_digito_vincula_o_cliente(): void
    {
        $cli = Cliente::factory()->create(['telefone' => '61991234567']);
        $this->postSim(['numero' => $this->num1->telefone, 'eventos' => [['tipo' => 'mensagem', 'id' => 'IN-9', 'de' => '556191234567', 'conteudo' => ['tipo' => 'texto', 'texto' => 'oi']]]])->assertOk();
        $this->assertSame($cli->id, WhatsappConversa::sole()->cliente_id);
    }

    // ---------------- Recebimento: Meta Cloud API (Http::fake, não homologado) ----------------

    private function numeroMeta(?Unidade $u = null): WhatsappNumero
    {
        return $this->numero($u ?? $this->u1, '6130000199', ['provedor' => 'meta', 'phone_number_id' => '1098765', 'modo_conexao' => 'coexistencia',
            'credenciais' => ['token' => 'TOKEN-TESTE', 'app_secret' => 'APP-SECRET-TESTE', 'verify_token' => 'verificacao-123456']]);
    }

    private function postMeta(array $payload, ?string $segredo = 'APP-SECRET-TESTE'): TestResponse
    {
        $corpo = json_encode($payload);

        return $this->call('POST', '/webhooks/whatsapp/meta', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, (string) $segredo)], $corpo);
    }

    private function metaValue(array $value): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages',
            'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '556130000199', 'phone_number_id' => '1098765']] + $value]]]]];
    }

    public function test_meta_verificacao_get_e_assinatura(): void
    {
        config(['oficina.integracoes' => 'real']);
        $this->numeroMeta();
        $this->get('/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=verificacao-123456&hub.challenge=987654')->assertOk()->assertSee('987654');
        $this->get('/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=987654')->assertForbidden();

        $payload = $this->metaValue(['contacts' => [['wa_id' => '5561991234567', 'profile' => ['name' => 'Mariana']]],
            'messages' => [['from' => '5561991234567', 'id' => 'wamid.AAA1', 'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => 'Olá pela Meta']]]]);
        $this->postMeta($payload, 'segredo-errado')->assertStatus(401);
        $this->assertSame(0, WhatsappMensagem::count());

        $this->postMeta($payload)->assertOk();
        $this->postMeta($payload)->assertOk(); // reentrega da Meta não duplica
        $m = WhatsappMensagem::sole();
        $this->assertSame(['wamid.AAA1', 'Olá pela Meta', 'Mariana'], [$m->id_provedor, $m->texto, $m->conversa->contato_nome]);
    }

    public function test_meta_envio_status_midia_e_coexistencia(): void
    {
        config(['oficina.integracoes' => 'real']);
        $n = $this->numeroMeta();
        Http::fake([
            'graph.facebook.com/v21.0/1098765/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
            'graph.facebook.com/v21.0/MIDIA1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=MIDIA1', 'mime_type' => 'image/png', 'file_size' => 70]),
            'lookaside.fbsbx.com/*' => Http::response(base64_decode(SimuladorWhatsApp::PNG), 200, ['Content-Type' => 'image/png']),
        ]);
        $ts = (string) now()->getTimestamp();
        $this->postMeta($this->metaValue(['messages' => [
            ['from' => '5561991234567', 'id' => 'wamid.IN1', 'timestamp' => $ts, 'type' => 'text', 'text' => ['body' => 'Oi']],
            ['from' => '5561991234567', 'id' => 'wamid.IN2', 'timestamp' => $ts, 'type' => 'image', 'image' => ['id' => 'MIDIA1', 'mime_type' => 'image/png', 'caption' => 'foto']],
        ]]))->assertOk();
        $img = WhatsappMensagem::where('id_provedor', 'wamid.IN2')->sole();
        $this->assertSame(['baixada', 'image/png'], [$img->midia_status, $img->midia_mime]);
        Storage::disk('local')->assertExists($img->midia_caminho);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'lookaside.fbsbx.com') && $r->hasHeader('Authorization', 'Bearer TOKEN-TESTE'));

        $c = $this->conversa($n, '61991234567');
        $resp = app(Atendimento::class)->responder($c, $this->rec1, 'Bom dia!');
        $this->assertSame(['enviada', 'wamid.OUT1'], [$resp->fresh()->status, $resp->fresh()->id_provedor]);
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v21.0/1098765/messages' && $r['to'] === '5561991234567'
            && $r['type'] === 'text' && $r['text']['body'] === 'Bom dia!' && $r->hasHeader('Authorization', 'Bearer TOKEN-TESTE'));

        // Status por webhook; fora de ordem não regride.
        $st = fn (string $s) => $this->postMeta($this->metaValue(['statuses' => [['id' => 'wamid.OUT1', 'status' => $s, 'timestamp' => $ts, 'recipient_id' => '5561991234567']]]))->assertOk();
        $st('read');
        $st('delivered');
        $this->assertSame('lida', $resp->fresh()->status);

        // Coexistência: eco do app do celular entra como saída "celular", sem autor, idempotente; zera a espera.
        $this->postMeta($this->metaValue(['messages' => [['from' => '5561991234567', 'id' => 'wamid.IN3', 'timestamp' => $ts, 'type' => 'text', 'text' => ['body' => 'E aí?']]]]));
        $this->assertNotNull($c->fresh()->aguardando_desde);
        $eco = $this->metaValue(['message_echoes' => [['from' => '556130000199', 'to' => '5561991234567', 'id' => 'wamid.ECO1', 'timestamp' => $ts, 'type' => 'text', 'text' => ['body' => 'Respondi do celular']]]]);
        $eco['entry'][0]['changes'][0]['field'] = 'smb_message_echoes';
        $this->postMeta($eco)->assertOk();
        $this->postMeta($eco)->assertOk();
        $m = WhatsappMensagem::where('id_provedor', 'wamid.ECO1')->sole();
        $this->assertSame(['saida', 'celular', null, 'enviada'], [$m->direcao, $m->origem, $m->autor_id, $m->status]);
        $this->assertNull($c->fresh()->aguardando_desde, 'resposta pelo celular conta como resposta');
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_resposta_celular']);
        $this->entrarComo($this->rec1)->get(route('whatsapp.show', $c))->assertOk()->assertSee('Enviada pelo celular');
    }

    public function test_meta_historico_importado_com_horario_original_sem_alerta(): void
    {
        config(['oficina.integracoes' => 'real']);
        $n = $this->numeroMeta();
        $antigo = now()->subDays(40)->getTimestamp();
        $payload = $this->metaValue(['history' => [['metadata' => ['phase' => 0], 'threads' => [['id' => '5561990000555', 'messages' => [
            ['from' => '5561990000555', 'id' => 'wamid.H1', 'timestamp' => (string) $antigo, 'type' => 'text', 'text' => ['body' => 'Mensagem antiga do cliente']],
            ['from' => '556130000199', 'id' => 'wamid.H2', 'timestamp' => (string) ($antigo + 60), 'type' => 'text', 'text' => ['body' => 'Resposta antiga do celular']],
        ]]]]]]);
        $payload['entry'][0]['changes'][0]['field'] = 'history';
        $this->postMeta($payload)->assertOk();
        $c = $this->conversa($n, '61990000555');
        $this->assertSame('resolvida', $c->situacao, 'conversa só de histórico não entra como pendente');
        $this->assertNull($c->aguardando_desde);
        $h1 = WhatsappMensagem::where('id_provedor', 'wamid.H1')->sole();
        $h2 = WhatsappMensagem::where('id_provedor', 'wamid.H2')->sole();
        $this->assertTrue($h1->historico);
        $this->assertSame($antigo, $h1->ocorrida_em->getTimestamp());
        $this->assertSame(['entrada', 'saida', 'celular'], [$h1->direcao, $h2->direcao, $h2->origem]);
        $this->assertCount(0, app(Atrasos::class)->semResposta($this->dono));
    }

    public function test_modo_real_sem_credenciais_falha_explicitamente(): void
    {
        config(['oficina.integracoes' => 'real']);
        $this->recebeDireto($this->num1, '61991234567');
        $c = $this->conversa($this->num1, '61991234567');
        $m = app(Atendimento::class)->responder($c, $this->rec1, 'teste');
        $this->assertSame('falhou', $m->fresh()->status);
        $this->assertStringContainsString('simulado', $m->fresh()->ultimo_erro);

        $meta = $this->numero($this->u1, '6130000188', ['provedor' => 'meta', 'phone_number_id' => '55555']);
        $this->recebeDireto($meta, '61991234567');
        $m2 = app(Atendimento::class)->responder($this->conversa($meta, '61991234567'), $this->rec1, 'teste');
        $this->assertSame('falhou', $m2->fresh()->status);
        $this->assertStringContainsString('Credenciais', $m2->fresh()->ultimo_erro);
        Http::assertNothingSent();
    }

    /** Entrada pelo caminho interno (evento normalizado), útil em modo real sem webhook do simulador. */
    private function recebeDireto(WhatsappNumero $n, string $tel): void
    {
        app(\App\Services\WhatsApp\Recebimento::class)->registrar('teste', [['evento_id' => 'msg:'.uniqid(), 'tipo' => 'mensagem', 'numero_id' => $n->id,
            'dados' => ['id' => uniqid('in'), 'contato' => '55'.$tel, 'nome' => null, 'direcao' => 'entrada', 'tipo' => 'texto', 'texto' => 'oi', 'timestamp' => now()->getTimestamp()]]]);
    }

    // ---------------- Autorização, triagem e encaminhamento ----------------

    public function test_triagem_encaminhamento_e_autorizacao_por_unidade(): void
    {
        $o = $this->ordem($this->u2, $this->rec2, '61991234567');
        $this->recebe($this->central, '61991234567', 'Preciso de ajuda');
        $c = $this->conversa($this->central, '61991234567');
        $this->assertNull($c->unidade_id, 'número central fica em triagem');

        // Sem triagem: recepção e gerente não veem; mecânico não acessa a Central.
        $this->entrarComo($this->rec1)->get(route('whatsapp.show', $c))->assertForbidden();
        $this->entrarComo($this->ger1)->get(route('whatsapp.show', $c))->assertForbidden();
        $this->entrarComo($this->mec1)->get(route('whatsapp.index'))->assertForbidden();
        $this->entrarComo($this->rec1)->get(route('whatsapp.index'))->assertOk()->assertDontSee('Preciso de ajuda');
        $this->entrarComo($this->rec1)->post(route('whatsapp.responder', $c), ['texto' => 'x'])->assertForbidden();

        // Proprietário vê triagem com sugestão (unidade da OS aberta mais recente) e encaminha com auditoria.
        $this->entrarComo($this->dono)->get(route('whatsapp.show', $c))->assertOk()->assertSee('Sugestão')->assertSee('Unidade Sul')->assertSee($o->numero);
        $this->entrarComo($this->rec1)->post(route('whatsapp.encaminhar', $c), ['unidade_id' => $this->u1->id])->assertForbidden();
        $this->entrarComo($this->dono)->post(route('whatsapp.encaminhar', $c), ['unidade_id' => $this->u2->id, 'observacao' => 'cliente da Sul'])->assertRedirect();
        $this->assertSame($this->u2->id, $c->fresh()->unidade_id);
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_encaminhada', 'entidade_id' => $c->id, 'ator_id' => $this->dono->id, 'unidade_id' => $this->u2->id]);

        // Agora a recepção da unidade de destino atende; a de outra unidade recebe 403 (inclusive na mídia).
        $this->entrarComo($this->rec2)->get(route('whatsapp.show', $c))->assertOk();
        $this->entrarComo($this->rec1)->get(route('whatsapp.show', $c))->assertForbidden();
        $this->entrarComo($this->rec1)->post(route('whatsapp.situacao', $c), ['situacao' => 'resolvida', 'motivo' => 'xxx'])->assertForbidden();
    }

    public function test_permissao_concedida_de_triagem_e_mecanico_nunca(): void
    {
        $this->recebe($this->central, '61991234567', 'Oi central');
        $c = $this->conversa($this->central, '61991234567');
        $this->rec1->forceFill(['permissoes_extras' => ['triagem_whatsapp']])->save();
        $this->entrarComo($this->rec1)->get(route('whatsapp.show', $c))->assertOk();
        $this->mec1->forceFill(['permissoes_extras' => ['triagem_whatsapp', 'atender_whatsapp']])->save();
        $this->entrarComo($this->mec1)->get(route('whatsapp.show', $c))->assertForbidden();
        $this->entrarComo($this->mec1)->get(route('whatsapp.index'))->assertForbidden();
    }

    // ---------------- Resposta, outbox, janela, falha ----------------

    public function test_resposta_enfileirada_status_por_webhook_e_alerta_some(): void
    {
        $this->freezeSecond(); // relógio real andando entre receber e responder dava 961 s em vez de 960 s
        $this->recebe($this->num1, '61991234567', 'Olá');
        $c = $this->conversa($this->num1, '61991234567');
        $this->travel(16)->minutes();
        $this->assertSame([$c->id], app(Atrasos::class)->semResposta($this->rec1)->pluck('id')->all(), 'alerta após o limite do número');
        $this->assertCount(0, app(Atrasos::class)->semResposta($this->rec2), 'outra unidade não vê o alerta');
        $this->assertSame(16, app(Atrasos::class)->semResposta($this->rec1)->first()->minutos_sem_resposta);

        Queue::fake([EnviarMensagemWhatsApp::class]); // só o envio fica parado; webhooks continuam processando
        $this->entrarComo($this->rec1)->post(route('whatsapp.responder', $c), ['texto' => 'Bom dia, já verifico'])->assertRedirect();
        $m = WhatsappMensagem::where('direcao', 'saida')->sole();
        $this->assertSame(['enviando', 'sistema', $this->rec1->id], [$m->status, $m->origem, $m->autor_id]);
        Queue::assertPushed(EnviarMensagemWhatsApp::class, fn ($j) => $j->mensagemId === $m->id);
        $this->assertCount(0, app(Atrasos::class)->semResposta($this->rec1), 'alerta some ao responder');
        $this->assertSame(['em_atendimento', $this->rec1->id], [$c->fresh()->situacao, $c->fresh()->responsavel_id]);
        $this->assertSame(16 * 60, $m->tempo_resposta_segundos);
        $this->assertEquals(16.0, app(Atrasos::class)->indicadores($this->rec1)['tempo_medio_primeira_resposta_min']);
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_respondida', 'ator_id' => $this->rec1->id]);

        (new EnviarMensagemWhatsApp($m->id))->handle();
        $m->refresh();
        $this->assertSame('enviada', $m->status);
        $this->assertStringStartsWith('SIM-WA-', $m->id_provedor, 'simulador: nada real enviado');
        $this->postSim(['numero' => $this->num1->telefone, 'eventos' => [['tipo' => 'status', 'id' => $m->id_provedor, 'status' => 'entregue']]])->assertOk();
        $this->assertSame('entregue', $m->fresh()->status);
        $this->postSim(['numero' => $this->num1->telefone, 'eventos' => [['tipo' => 'status', 'id' => $m->id_provedor, 'status' => 'lida']]])->assertOk();
        $this->assertSame('lida', $m->fresh()->status);
        $this->assertNotNull($m->fresh()->lida_em);
    }

    public function test_janela_de_24h_bloqueia_texto_livre_e_permite_modelo(): void
    {
        $this->recebe($this->num1, '61991234567', 'Olá');
        $c = $this->conversa($this->num1, '61991234567');
        $this->travel(25)->hours();
        $this->entrarComo($this->rec1)->get(route('whatsapp.show', $c))->assertOk()->assertSee('Fora da janela de 24 h');
        $this->entrarComo($this->rec1)->post(route('whatsapp.responder', $c), ['texto' => 'texto livre'])->assertSessionHasErrors('texto');
        $this->assertSame(0, WhatsappMensagem::where('direcao', 'saida')->count());
        $this->entrarComo($this->rec1)->post(route('whatsapp.modelo', $c), ['modelo' => 'inexistente'])->assertSessionHasErrors('modelo');
        $this->entrarComo($this->rec1)->post(route('whatsapp.modelo', $c), ['modelo' => 'retomar_contato'])->assertRedirect();
        $m = WhatsappMensagem::where('direcao', 'saida')->sole();
        $this->assertSame(['modelo', 'retomar_contato', 'enviada'], [$m->tipo, $m->modelo, $m->status]);
    }

    public function test_falha_de_envio_visivel_com_tentar_de_novo_e_alerta_restaurado(): void
    {
        $this->recebe($this->num1, '61988880000', 'Olá'); // simulador: destino final 0000 indisponível
        $c = $this->conversa($this->num1, '61988880000');
        $this->entrarComo($this->rec1)->post(route('whatsapp.responder', $c), ['texto' => 'Resposta'])->assertRedirect();
        $m = WhatsappMensagem::where('direcao', 'saida')->sole();
        $this->assertSame(['falhou', 1], [$m->status, $m->tentativas]);
        $this->assertNotNull($c->fresh()->aguardando_desde, 'falha definitiva: conversa volta a contar como sem resposta');
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_envio_falhou']);
        $this->entrarComo($this->rec1)->get(route('whatsapp.show', $c))->assertOk()->assertSee('Tentar de novo')->assertSee('Não enviada');

        $this->entrarComo($this->rec2)->post(route('whatsapp.tentar', $m))->assertForbidden();
        Queue::fake();
        $this->entrarComo($this->rec1)->post(route('whatsapp.tentar', $m))->assertRedirect();
        $this->assertSame('enviando', $m->fresh()->status);
        Queue::assertPushed(EnviarMensagemWhatsApp::class);
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_reenvio']);
    }

    // ---------------- Situação, reabertura, vínculos ----------------

    public function test_resolver_exige_motivo_e_nova_mensagem_reabre_com_auditoria(): void
    {
        $this->recebe($this->num1, '61991234567', 'Olá');
        $c = $this->conversa($this->num1, '61991234567');
        $this->entrarComo($this->rec1)->post(route('whatsapp.situacao', $c), ['situacao' => 'resolvida'])->assertSessionHasErrors('motivo');
        $this->entrarComo($this->rec1)->post(route('whatsapp.situacao', $c), ['situacao' => 'resolvida', 'motivo' => 'Dúvida respondida'])->assertRedirect();
        $this->assertSame(['resolvida', null], [$c->fresh()->situacao, $c->fresh()->aguardando_desde]);
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_situacao', 'motivo' => 'Dúvida respondida']);

        $this->recebe($this->num1, '61991234567', 'Mais uma coisa');
        $this->assertSame('nova', $c->fresh()->situacao);
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_conversa_reaberta', 'entidade_id' => $c->id]);

        $this->entrarComo($this->rec1)->post(route('whatsapp.situacao', $c), ['situacao' => 'aguardando_cliente'])->assertRedirect();
        $this->recebe($this->num1, '61991234567', 'Respondendo');
        $this->assertSame('em_atendimento', $c->fresh()->situacao);
        $this->assertSame(1, WhatsappConversa::count(), 'uma conversa por (número, contato)');
    }

    public function test_vinculo_manual_de_cliente_e_os_auditado(): void
    {
        $o = $this->ordem($this->u1, $this->rec1, '61977776666');
        $outra = $this->ordem($this->u1, $this->rec1, '61955554444', 'XYZ9A88');
        $this->recebe($this->num1, '61990001111', 'Sou a Mariana, mudei de número');
        $c = $this->conversa($this->num1, '61990001111');
        $this->assertNull($c->cliente_id);
        $this->entrarComo($this->rec1)->get(route('whatsapp.show', [$c, 'busca_cliente' => 'Mariana']))->assertOk()->assertSee('Vincular Mariana Costa');
        $this->entrarComo($this->rec1)->post(route('whatsapp.cliente', $c), ['cliente_id' => $o->cliente_id])->assertRedirect();
        $this->assertSame($o->cliente_id, $c->fresh()->cliente_id);
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_vinculo_cliente', 'entidade_id' => $c->id]);
        $this->entrarComo($this->rec1)->get(route('whatsapp.show', $c))->assertSee($o->numero)->assertSee('OS abertas do cliente');

        $this->entrarComo($this->rec1)->post(route('whatsapp.ordem', $c), ['ordem' => $outra->numero])->assertSessionHasErrors('ordem');
        $this->entrarComo($this->rec1)->post(route('whatsapp.ordem', $c), ['ordem' => $o->numero])->assertRedirect();
        $this->assertSame($o->id, $c->fresh()->ordem_id);
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_vinculo_ordem']);
        $this->entrarComo($this->rec1)->post(route('whatsapp.atribuir', $c), ['responsavel_id' => $this->rec2->id])->assertSessionHasErrors('responsavel_id');
        $this->entrarComo($this->rec1)->post(route('whatsapp.atribuir', $c), ['responsavel_id' => $this->ger1->id])->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_atribuida']);
    }

    // ---------------- Mídia ----------------

    public function test_midia_baixada_validada_e_download_autorizado_pela_conversa(): void
    {
        $midia = fn (string $id, string $mime) => $this->recebe($this->num1, '61991234567', '', 'IN-'.$id, ['conteudo' => ['tipo' => 'documento', 'midia_id' => $id, 'mime' => $mime, 'nome' => 'arq']]);
        $midia('SIM-MIDIA-documento-1', 'application/pdf');
        $midia('SIM-MIDIA-executavel-1', 'application/pdf'); // declara PDF mas é executável
        config(['oficina.whatsapp.midia_max_bytes' => 1000]);
        $midia('SIM-MIDIA-grande-1', 'image/png');
        $ok = WhatsappMensagem::where('midia_id_provedor', 'SIM-MIDIA-documento-1')->sole();
        $exe = WhatsappMensagem::where('midia_id_provedor', 'SIM-MIDIA-executavel-1')->sole();
        $grande = WhatsappMensagem::where('midia_id_provedor', 'SIM-MIDIA-grande-1')->sole();
        $this->assertSame(['baixada', 'application/pdf'], [$ok->midia_status, $ok->midia_mime]);
        $this->assertSame('rejeitada', $exe->midia_status);
        $this->assertNull($exe->midia_caminho);
        $this->assertSame('rejeitada', $grande->midia_status);

        $this->entrarComo($this->rec1)->get(route('whatsapp.midia', $ok))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->entrarComo($this->rec2)->get(route('whatsapp.midia', $ok))->assertForbidden();
        $this->entrarComo($this->mec1)->get(route('whatsapp.midia', $ok))->assertForbidden();
        $this->entrarComo($this->rec1)->get(route('whatsapp.midia', $exe))->assertNotFound();
    }

    // ---------------- Telas, privacidade, números, simulação ----------------

    public function test_telas_central_e_conversa_com_filtros_e_telefone_mascarado(): void
    {
        $this->recebe($this->num1, '61991234567', 'Mensagem visível na lista');
        $c = $this->conversa($this->num1, '61991234567');
        $this->entrarComo($this->rec1)->get(route('whatsapp.index'))->assertOk()->assertSee('Central de WhatsApp')
            ->assertSee('Mensagem visível na lista')->assertSee('(61) •••••-4567')->assertDontSee('(61) 99123-4567');
        $this->entrarComo($this->ger1)->get(route('whatsapp.index'))->assertSee('(61) 99123-4567'); // gestor vê completo
        $this->entrarComo($this->rec1)->get(route('whatsapp.index', ['situacao' => 'resolvida']))->assertDontSee('Mensagem visível na lista');
        $this->entrarComo($this->rec1)->get(route('whatsapp.index', ['busca' => '4567']))->assertSee('Mensagem visível na lista');
        $this->entrarComo($this->rec1)->get(route('whatsapp.index', ['responsavel' => 'eu']))->assertDontSee('Mensagem visível na lista');
        $this->entrarComo($this->rec1)->get(route('whatsapp.show', $c))->assertOk()->assertSee('Responder pelo WhatsApp oficial')->assertSee('wa-msg entrada', false);
    }

    public function test_cadastro_de_numero_so_proprietario_com_reautenticacao_credenciais_ocultas_e_auditado(): void
    {
        $this->entrarComo($this->ger1)->get(route('whatsapp.numeros.index'))->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->dono)->withSession(['dois_fatores_ok' => $this->dono->id])->get(route('whatsapp.numeros.index'))->assertRedirect('/confirmar-senha');

        $this->entrarComo($this->dono)->post(route('whatsapp.numeros.store'), [
            'nome' => 'Meta Norte', 'telefone' => '(61) 3000-0177', 'unidade_id' => $this->u1->id, 'provedor' => 'meta', 'modo_conexao' => 'coexistencia',
            'phone_number_id' => '777888', 'token' => 'TOKEN-SUPER-SECRETO', 'app_secret' => 'APP-SECRETO', 'verify_token' => 'verificacao-xyz-123',
            'minutos_alerta' => 20, 'modelos' => "retomar_contato|pt_BR|Retomar\naviso_pronto|pt_BR",
        ])->assertRedirect(route('whatsapp.numeros.index'));
        $n = WhatsappNumero::where('telefone', '6130000177')->sole();
        $this->assertSame(['coexistencia', 20, 2], [$n->modo_conexao, $n->minutos_alerta, count($n->modelos)]);
        $this->assertStringNotContainsString('TOKEN-SUPER-SECRETO', (string) DB::table('whatsapp_numeros')->where('id', $n->id)->value('credenciais'), 'cifrado no banco');
        $pagina = $this->entrarComo($this->dono)->get(route('whatsapp.numeros.index'))->assertOk()->assertSee('salvas (não exibidas)');
        $pagina->assertDontSee('TOKEN-SUPER-SECRETO')->assertDontSee('APP-SECRETO');
        $evento = DB::table('audit_events')->where('acao', 'whatsapp_numero_criado')->sole();
        $this->assertStringNotContainsString('TOKEN-SUPER-SECRETO', $evento->depois);

        // Alterar sem informar credenciais mantém as salvas.
        $this->entrarComo($this->dono)->put(route('whatsapp.numeros.update', $n), ['nome' => 'Meta Norte 2', 'telefone' => '6130000177', 'unidade_id' => $this->u1->id,
            'provedor' => 'meta', 'modo_conexao' => 'api', 'phone_number_id' => '777888', 'minutos_alerta' => 15, 'ativo' => '1'])->assertRedirect();
        $this->assertSame(['TOKEN-SUPER-SECRETO', 'Meta Norte 2'], [$n->fresh()->credencial('token'), $n->fresh()->nome]);
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_numero_alterado']);
        $this->entrarComo($this->dono)->post(route('whatsapp.numeros.store'), ['nome' => 'x', 'telefone' => '6130000177', 'provedor' => 'meta', 'modo_conexao' => 'api', 'minutos_alerta' => 15])
            ->assertSessionHasErrors(['phone_number_id']);
    }

    public function test_simular_mensagem_e_resposta_pelo_celular_somente_fora_de_producao(): void
    {
        $this->entrarComo($this->rec1)->post(route('whatsapp.simular'), ['numero_id' => $this->central->id, 'telefone' => '61991234567', 'tipo' => 'texto', 'texto' => 'x'])->assertForbidden();
        $this->entrarComo($this->rec1)->post(route('whatsapp.simular'), ['numero_id' => $this->num1->id, 'telefone' => '61991234567', 'tipo' => 'imagem', 'texto' => 'foto'])->assertRedirect();
        $c = $this->conversa($this->num1, '61991234567');
        $this->assertSame(['imagem', 'baixada'], [$c->mensagens->first()->tipo, $c->mensagens->first()->midia_status]);

        $this->entrarComo($this->rec1)->post(route('whatsapp.simular-celular', $c), ['texto_celular' => 'Respondi pelo celular'])->assertRedirect();
        $eco = WhatsappMensagem::where('origem', 'celular')->sole();
        $this->assertSame(['saida', null], [$eco->direcao, $eco->autor_id]);
        $this->assertNull($c->fresh()->aguardando_desde);

        $this->app['env'] = 'production';
        $this->assertFalse(\App\Integracoes\WhatsApp\Provedores::podeSimular());
        // Em "production" o CSRF volta a valer: envia token válido para chegar à regra testada.
        $this->entrarComo($this->rec1)->withSession(['_token' => 'tok'])->post(route('whatsapp.simular'), ['_token' => 'tok', 'numero_id' => $this->num1->id, 'telefone' => '61991234567', 'tipo' => 'texto'])->assertNotFound();
        $this->entrarComo($this->rec1)->withSession(['_token' => 'tok'])->post(route('whatsapp.simular-celular', $c), ['_token' => 'tok', 'texto_celular' => 'x'])->assertNotFound();
        $this->assertSame(1, WhatsappMensagem::where('origem', 'celular')->count());
    }

    public function test_banco_impoe_regras_basicas(): void
    {
        $this->recebe($this->num1, '61991234567', 'oi');
        $c = $this->conversa($this->num1, '61991234567');
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('whatsapp_conversas')->where('id', $c->id)->update(['situacao' => 'resolvida', 'motivo_resolucao' => null, 'resolvida_em' => now()]);
    }

    // ---------------- Regressões da revisão de segurança ----------------

    public function test_erro_de_validacao_nao_guarda_credenciais_na_sessao(): void
    {
        $this->entrarComo($this->dono)->post(route('whatsapp.numeros.store'), ['nome' => 'Dup', 'telefone' => $this->num1->telefone, 'provedor' => 'meta',
            'modo_conexao' => 'api', 'phone_number_id' => '123456', 'token' => 'TOKEN-QUE-NAO-PODE-VAZAR', 'minutos_alerta' => 15])
            ->assertSessionHasErrors('telefone')->assertSessionHasInput('nome', 'Dup')->assertSessionMissing('_old_input.token');
        $this->assertStringNotContainsString('TOKEN-QUE-NAO-PODE-VAZAR', json_encode(session()->all()));
    }

    public function test_os_de_outra_unidade_nao_e_vinculada_a_conversa(): void
    {
        $o2 = $this->ordem($this->u2, $this->rec2, '61991234567');
        $this->recebe($this->num1, '61991234567', 'oi');
        $c = $this->conversa($this->num1, '61991234567');
        $this->assertSame($o2->cliente_id, $c->cliente_id);
        $this->entrarComo($this->dono)->post(route('whatsapp.ordem', $c), ['ordem' => $o2->numero])->assertSessionHasErrors('ordem');
        $this->assertNull($c->fresh()->ordem_id);
    }

    public function test_busca_por_telefone_nao_contorna_a_mascara(): void
    {
        $this->recebe($this->num1, '61991234567', 'Contato mascarado');
        $this->entrarComo($this->rec1)->get(route('whatsapp.index', ['busca' => '99123']))->assertDontSee('Contato mascarado');
        $this->entrarComo($this->rec1)->get(route('whatsapp.index', ['busca' => '4567']))->assertSee('Contato mascarado');
        $this->entrarComo($this->ger1)->get(route('whatsapp.index', ['busca' => '99123']))->assertSee('Contato mascarado');
    }

    public function test_numero_desativado_nao_envia(): void
    {
        $this->recebe($this->num1, '61991234567', 'oi');
        $c = $this->conversa($this->num1, '61991234567');
        $this->num1->update(['ativo' => false]);
        $this->entrarComo($this->rec1)->post(route('whatsapp.responder', $c), ['texto' => 'x'])->assertSessionHasErrors('texto');
        $this->assertSame(0, WhatsappMensagem::where('direcao', 'saida')->count());
    }

    public function test_conversa_sem_resposta_aparece_no_sino_menu_e_painel_so_para_quem_atende(): void
    {
        $this->recebeDireto($this->num1, '61988887777');
        $this->travel(16)->minutes();

        $html = $this->entrarComo($this->rec1)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('WhatsApp sem resposta há 16 min', $html, 'sino da recepção da unidade');
        $this->assertStringContainsString('Central de WhatsApp', $html);
        $this->assertStringContainsString('WhatsApp (agora)', $html, 'cartão do painel');
        $this->assertStringContainsString(route('whatsapp.show', $this->conversa($this->num1, '61988887777')), $html);
        $this->assertStringNotContainsString('WhatsApp sem resposta', $this->entrarComo($this->rec2)->get('/')->getContent(), 'outra unidade');
        $mec = $this->entrarComo($this->mec1)->get('/fila')->assertOk()->getContent();
        $this->assertStringNotContainsString('Central de WhatsApp', $mec);
        $this->assertStringNotContainsString('WhatsApp sem resposta', $mec);
    }

    public function test_cadastra_cliente_novo_pela_conversa_sem_duplicar(): void
    {
        $this->recebeDireto($this->num1, '61977776666');
        $c = $this->conversa($this->num1, '61977776666');
        $this->assertNull($c->cliente_id);
        $url = route('whatsapp.cliente.novo', $c);

        $this->entrarComo($this->rec1)->get(route('whatsapp.show', $c))->assertOk()->assertSee('Cadastrar novo cliente');
        $this->entrarComo($this->rec2)->post($url, ['nome' => 'Intruso'])->assertForbidden();
        $this->entrarComo($this->mec1)->post($url, ['nome' => 'Mecânico'])->assertForbidden();
        $this->entrarComo($this->rec1)->post($url, ['nome' => ''])->assertSessionHasErrors('nome');

        $this->entrarComo($this->rec1)->post($url, ['nome' => 'Júlia Nova', 'email' => 'julia@exemplo.test'])->assertRedirect();
        $cliente = \App\Models\Cliente::where('telefone', '61977776666')->sole();
        $this->assertSame(['Júlia Nova', 'julia@exemplo.test'], [$cliente->nome, $cliente->email]);
        $this->assertSame($cliente->id, $c->fresh()->cliente_id);
        $this->assertDatabaseHas('audit_events', ['acao' => 'cliente_criado', 'entidade_id' => $cliente->id]);
        $this->entrarComo($this->rec1)->post($url, ['nome' => 'De novo'])->assertSessionHasErrors('nome');
        $this->entrarComo($this->rec1)->get(route('whatsapp.show', $c))->assertSee('Registrar entrada de veículo');

        // Outro número com o mesmo telefone (sem o 9º dígito): não cria segundo cadastro.
        $num2 = $this->numero($this->u1, '6130000102');
        $this->recebeDireto($num2, '6177776666');
        $c2 = $this->conversa($num2, '6177776666');
        if (! $c2->cliente_id) {
            $this->entrarComo($this->rec1)->post(route('whatsapp.cliente.novo', $c2), ['nome' => 'Duplicada'])->assertSessionHasErrors('nome');
        }
        $this->assertSame(1, \App\Models\Cliente::where('nome', 'like', '%Nova%')->orWhere('nome', 'Duplicada')->count());

        $this->entrarComo($this->rec1)->get('/entradas/nova?telefone=61977776666&nome=J%C3%BAlia+Nova')->assertOk()->assertSee('61977776666')->assertSee('Júlia Nova');
    }
}
