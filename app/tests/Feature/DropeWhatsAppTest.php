<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Integracoes\WhatsApp\DropeApi;
use App\Integracoes\WhatsApp\MidiaRecusada;
use App\Jobs\EnviarMensagem;
use App\Models\MessageDelivery;
use App\Models\Unidade;
use App\Models\User;
use App\Models\WhatsappConversa;
use App\Models\WhatsappMensagem;
use App\Models\WhatsappNumero;
use App\Services\Entradas;
use App\Services\Mensagens;
use App\Services\Orcamentos;
use App\Services\WhatsApp\Atendimento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Conector Drope (QR Code, não oficial). Rede sempre por Http::fake; formato do webhook é hipótese até captura real. */
class DropeWhatsAppTest extends TestCase
{
    private const SEGREDO = 'whsec-teste-drope';

    private Unidade $u1;
    private User $dono;
    private User $rec1;
    private WhatsappNumero $n;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'real']);
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->u1 = Unidade::factory()->create();
        $this->dono = $this->usuario(Papel::Proprietario);
        $this->rec1 = $this->usuario(Papel::Recepcao, $this->u1);
        $this->n = WhatsappNumero::create(['nome' => 'WhatsApp Loja', 'telefone' => '6130000300', 'unidade_id' => $this->u1->id, 'provedor' => 'drope',
            'minutos_alerta' => 15, 'criado_por' => $this->dono->id, 'modelos' => [], 'credenciais' => ['token' => 'dwa_TESTE', 'signing_secret' => self::SEGREDO]]);
    }

    private function webhook(array $payload, ?string $segredo = self::SEGREDO, ?int $numero = null, string $evento = 'messages'): TestResponse
    {
        $corpo = json_encode($payload);

        return $this->call('POST', '/webhooks/whatsapp/drope/'.($numero ?? $this->n->id), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_DROPE_EVENT' => $evento, 'HTTP_X_DROPE_DELIVERY' => 'dlv-1', 'HTTP_X_DROPE_SIGNATURE' => 'sha256='.hash_hmac('sha256', $corpo, (string) $segredo)], $corpo);
    }

    private function msg(array $m): array
    {
        return ['EventType' => 'messages', 'message' => $m + ['chatid' => '5561991234567@s.whatsapp.net', 'sender' => '5561991234567@s.whatsapp.net',
            'senderName' => 'Mariana', 'isGroup' => false, 'fromMe' => false, 'wasSentByApi' => false, 'messageTimestamp' => now()->getTimestampMs()]];
    }

    public function test_webhook_assinado_por_numero_cria_conversa_sem_duplicar_e_ignora_grupo_e_api(): void
    {
        $p = $this->msg(['messageid' => '3EB0AAA1', 'messageType' => 'Conversation', 'text' => 'Olá pelo Drope']);
        $this->webhook($p, 'segredo-errado')->assertStatus(401);
        $outro = WhatsappNumero::create(['nome' => 'X', 'telefone' => '6130000301', 'provedor' => 'drope', 'minutos_alerta' => 15, 'criado_por' => $this->dono->id,
            'modelos' => [], 'credenciais' => ['token' => 'dwa_X', 'signing_secret' => 'outro']]);
        $this->webhook($p, self::SEGREDO, $outro->id)->assertStatus(401); // segredo de um número não vale para outro
        $this->assertSame(0, WhatsappMensagem::count());

        $this->webhook($p)->assertOk();
        $this->webhook($p)->assertOk(); // retentativa do Drope não duplica
        $m = WhatsappMensagem::sole();
        $this->assertSame(['3EB0AAA1', 'Olá pelo Drope', 'Mariana', 'entrada'], [$m->id_provedor, $m->texto, $m->conversa->contato_nome, $m->direcao]);
        $this->assertSame($this->n->id, $m->conversa->numero_id);

        $this->webhook($this->msg(['messageid' => 'G1', 'messageType' => 'Conversation', 'text' => 'grupo', 'isGroup' => true]))->assertOk();
        $this->webhook($this->msg(['messageid' => 'API1', 'messageType' => 'Conversation', 'text' => 'api', 'fromMe' => true, 'wasSentByApi' => true]))->assertOk();
        $this->webhook($this->msg(['messageid' => 'ECO1', 'messageType' => 'Conversation', 'text' => 'Respondi do celular', 'fromMe' => true]))->assertOk();
        $this->assertSame(['3EB0AAA1', 'ECO1'], WhatsappMensagem::orderBy('id')->pluck('id_provedor')->all());
        $eco = WhatsappMensagem::where('id_provedor', 'ECO1')->sole();
        $this->assertSame(['saida', 'celular'], [$eco->direcao, $eco->origem]);
    }

    public function test_rota_drope_nao_existe_em_simulacao_e_numero_inativo_recusa(): void
    {
        config(['oficina.integracoes' => 'simulado']);
        $this->webhook($this->msg(['messageid' => 'A', 'text' => 'x']))->assertNotFound();
        config(['oficina.integracoes' => 'real']);
        $this->n->update(['ativo' => false]);
        $this->webhook($this->msg(['messageid' => 'A', 'text' => 'x']))->assertStatus(401);
    }

    public function test_botao_desativa_e_reativa_o_numero(): void
    {
        $url = route('whatsapp.numeros.ativo', $this->n);
        $this->entrarComo($this->dono)->get(route('whatsapp.numeros.index'))->assertSee('Desativar número');
        $this->entrarComo($this->rec1)->put($url)->assertForbidden();
        $this->entrarComo($this->dono)->put($url)->assertRedirect();
        $this->assertFalse($this->n->fresh()->ativo);
        $this->assertNull(\App\Integracoes\Mensagens\MensageiroDrope::paraUnidade($this->u1->id), 'desativado não envia');
        $this->webhook($this->msg(['messageid' => 'X1', 'messageType' => 'Conversation', 'text' => 'oi']))->assertStatus(401);
        $this->entrarComo($this->dono)->get(route('whatsapp.numeros.index'))->assertSee('Ativar número');
        $this->entrarComo($this->dono)->put($url)->assertRedirect();
        $this->assertTrue($this->n->fresh()->ativo);
        $this->assertSame(2, \App\Models\AuditEvent::where('acao', 'whatsapp_numero_alterado')->count());
    }

    public function test_payload_desconhecido_loga_so_a_estrutura_sem_valores(): void
    {
        Log::spy();
        $this->webhook(['EventType' => 'messages', 'formato' => ['novo' => 'TEXTO-SIGILOSO-DO-CLIENTE']])->assertOk();
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'drope não reconhecido')
            && $ctx['estrutura'] === ['EventType' => 'string', 'formato' => ['novo' => 'string']] && ! str_contains(json_encode($ctx), 'SIGILOSO'));
    }

    public function test_status_por_messages_update(): void
    {
        Http::fake(['dropestore.com/wp-json/wdm/v1/send/text' => Http::response(['success' => true, 'data' => ['messageid' => '3EB0OUT1']])]);
        $this->webhook($this->msg(['messageid' => 'IN1', 'messageType' => 'Conversation', 'text' => 'Oi']))->assertOk();
        $resp = app(Atendimento::class)->responder(WhatsappConversa::sole(), $this->rec1, 'Bom dia!');
        $this->webhook(['EventType' => 'messages_update', 'event' => ['Type' => 'Read', 'MessageIDs' => ['3EB0OUT1']]], evento: 'messages_update')->assertOk();
        $this->assertSame('lida', $resp->fresh()->status);
    }

    public function test_envio_texto_e_pdf_em_base64_sem_janela_de_24h(): void
    {
        Http::fake(['dropestore.com/wp-json/wdm/v1/send/*' => Http::response(['success' => true, 'data' => ['messageid' => '3EB0OUT1']])]);
        $this->webhook($this->msg(['messageid' => 'IN1', 'messageType' => 'Conversation', 'text' => 'Oi', 'messageTimestamp' => now()->subDays(3)->getTimestampMs()]))->assertOk();
        $c = WhatsappConversa::sole();
        $this->assertTrue($c->fresh()->janelaAberta(), 'Drope não tem janela de 24 h');

        $r = app(Atendimento::class)->responder($c, $this->rec1, 'Bom dia!');
        $this->assertSame(['enviada', '3EB0OUT1'], [$r->fresh()->status, $r->fresh()->id_provedor]);
        Http::assertSent(fn ($req) => $req->url() === 'https://dropestore.com/wp-json/wdm/v1/send/text' && $req->hasHeader('token', 'dwa_TESTE')
            && $req['number'] === '5561991234567' && $req['text'] === 'Bom dia!');

        $pdf = "%PDF-1.4\n%%EOF\n";
        (new DropeApi)->enviarMidia($this->n, '5561991234567', $pdf, 'application/pdf', 'OS-SN-00007.pdf', 'Sua OS');
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/send/media') && $req['type'] === 'document' && $req['file'] === base64_encode($pdf)
            && $req['docName'] === 'OS-SN-00007.pdf' && $req['mimetype'] === 'application/pdf' && $req['text'] === 'Sua OS');
    }

    public function test_aparelho_desconectado_vira_mensagem_clara(): void
    {
        Http::fake(['dropestore.com/*' => Http::response(['success' => false, 'error' => 'Device disconnected', 'code' => 'DEVICE_DISCONNECTED'], 409)]);
        $this->expectExceptionMessage('aparelho desconectado no Drope');
        (new DropeApi)->enviarTexto($this->n, '5561991234567', 'Oi');
    }

    public function test_download_de_midia_em_base64_com_limite(): void
    {
        Http::fake(['dropestore.com/wp-json/wdm/v1/message/download' => Http::response(['base64Data' => base64_encode('%PDF-1.4 x'), 'mimetype' => 'application/pdf'])]);
        $r = (new DropeApi)->baixarMidia($this->n, '3EB0IN', 1000);
        $this->assertSame(['%PDF-1.4 x', 'application/pdf'], [$r['conteudo'], $r['mime']]);
        Http::assertSent(fn ($req) => $req['id'] === '3EB0IN' && $req['return_base64'] === true);
        $this->expectException(MidiaRecusada::class);
        (new DropeApi)->baixarMidia($this->n, '3EB0IN', 5);
    }

    public function test_dono_registra_webhook_e_segredo_fica_cifrado_recepcao_nao(): void
    {
        $this->n->update(['credenciais' => ['token' => 'dwa_TESTE']]);
        Http::fake(['dropestore.com/wp-json/wdm/v1/webhook' => Http::response(['success' => true, 'data' => [
            ['id' => 'wh0', 'enabled' => true, 'url' => 'https://outro.exemplo/hook', 'events' => ['messages'], 'excludeMessages' => [], 'addUrlEvents' => false, 'addUrlTypesMessages' => false, 'signing_secret' => 'whsec-outro'],
            ['id' => 'wh1', 'enabled' => true, 'url' => rtrim(config('app.url'), '/').'/webhooks/whatsapp/drope/'.$this->n->id, 'events' => ['messages'], 'excludeMessages' => ['wasSentByApi'],
                'addUrlEvents' => false, 'addUrlTypesMessages' => false, 'signing_secret' => 'whsec-novo'],
        ]])]);
        $this->entrarComo($this->rec1)->post(route('whatsapp.numeros.drope-webhook', $this->n))->assertForbidden();

        $this->entrarComo($this->dono)->post(route('whatsapp.numeros.drope-webhook', $this->n))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('whsec-novo', $this->n->fresh()->credencial('signing_secret'));
        $this->assertStringNotContainsString('whsec-novo', (string) DB::table('whatsapp_numeros')->where('id', $this->n->id)->value('credenciais'));
        Http::assertSent(fn ($req) => $req['url'] === rtrim(config('app.url'), '/').'/webhooks/whatsapp/drope/'.$this->n->id && $req->hasHeader('token', 'dwa_TESTE')
            && $req['events'] === ['messages', 'messages_update', 'connection']);
        $this->assertDatabaseHas('audit_events', ['acao' => 'whatsapp_drope_webhook_registrado']);
        $this->entrarComo($this->dono)->get(route('whatsapp.numeros.index'))->assertOk()->assertSee('Registrar webhook no Drope de novo')->assertDontSee('whsec-novo');
    }

    public function test_os_e_mensagens_automaticas_saem_pelo_numero_drope_da_unidade(): void
    {
        Queue::fake();
        Http::fake(['dropestore.com/wp-json/wdm/v1/send/text' => Http::response(['success' => true, 'data' => ['messageid' => '3EB0OS1']])]);
        $o = app(Entradas::class)->registrar($this->rec1, ['unidade_id' => $this->u1->id, 'nome' => 'Mariana Costa', 'telefone' => '61991234567', 'placa' => 'RDA1B23', 'motivo' => 'Revisão']);
        $m = app(Mensagens::class)->enfileirarDocumentoOs($o, app(Orcamentos::class)->novoLink($o, $this->rec1));
        (new EnviarMensagem($m->id))->handle(app(\App\Integracoes\Mensagens\Mensageiro::class));
        $this->assertSame(['enviado', '3EB0OS1'], [$m->fresh()->status, $m->fresh()->id_provedor]);
        Http::assertSent(fn ($req) => $req['number'] === '5561991234567' && str_contains($req['text'], $o->numero) && str_contains($req['text'], '/c/'));

        // Mensagens automáticas também saem pelo Drope.
        $entrada = MessageDelivery::where('ordem_id', $o->id)->where('tipo', 'entrada')->sole();
        (new EnviarMensagem($entrada->id))->handle(app(\App\Integracoes\Mensagens\Mensageiro::class));
        $this->assertSame('enviado', $entrada->fresh()->status);
        Http::assertSentCount(2);

        // Sem número Drope: continua indisponível (nada sai).
        $this->n->update(['ativo' => false]);
        $outra = app(Mensagens::class)->enfileirarDocumentoOs($o, app(Orcamentos::class)->novoLink($o, $this->rec1));
        try {
            (new EnviarMensagem($outra->id))->handle(app(\App\Integracoes\Mensagens\Mensageiro::class));
            $this->fail('deveria falhar sem canal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nenhum número de WhatsApp ativo', $e->getMessage());
        }
        Http::assertSentCount(2);
    }
}
