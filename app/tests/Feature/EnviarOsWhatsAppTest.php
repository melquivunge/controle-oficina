<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Integracoes\Mensagens\Mensageiro;
use App\Models\AuditEvent;
use App\Models\Cliente;
use App\Models\LinkCliente;
use App\Models\MessageDelivery;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Mensagens;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Enviar OS ao cliente por WhatsApp: outbox oficial (documento_os, simulado) e "Abrir no meu WhatsApp" (wa.me). */
class EnviarOsWhatsAppTest extends TestCase
{
    private Unidade $unidade;
    private User $gerente;
    private User $recepcao;
    private User $mecanico;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // nenhuma chamada externa real
        $this->unidade = Unidade::factory()->create();
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
    }

    private function ordemAprovada(): Ordem
    {
        $o = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana Costa', 'telefone' => '61991234567',
            'placa' => 'RDA1B23', 'modelo' => 'Onix', 'motivo' => 'Jogo de rodas aro 17', 'observacoes' => 'NOTA-INTERNA-RECEPCAO']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->mecanico);
        app(Ordens::class)->registrarDiagnostico($o, 'DIAGNOSTICO-INTERNO empeno leve.', $this->mecanico);
        $v = app(Orcamentos::class)->salvarRascunho($o, $this->gerente, [['descricao' => 'Reforma de roda aro 17', 'quantidade' => '4', 'preco' => '250,00']]);
        $this->entrarComo($this->gerente)->post(route('orcamentos.enviar', [$o, $v]))->assertSessionHasNoErrors();
        app(Orcamentos::class)->decidirPresencial($v->fresh(), $this->recepcao, 'aprovado', 'Cliente aprovou no balcão');
        app(Financeiro::class)->registrarManual($o->fresh(), $this->recepcao, 'dinheiro', 40000, 'Sinal');

        return $o->fresh();
    }

    public function test_recepcao_envia_pelo_outbox_com_link_do_portal_valido_e_texto_sem_dados_internos(): void
    {
        $o = $this->ordemAprovada();
        Queue::fake();
        $this->entrarComo($this->recepcao)->post(route('ordens.os.whatsapp', $o), ['canal' => 'sistema'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $m = MessageDelivery::where('ordem_id', $o->id)->where('tipo', 'documento_os')->sole();
        $this->assertSame('pendente', $m->status);
        $this->assertSame('simulado', $m->canal);
        $this->assertSame('61991234567', $m->destino);
        $this->assertStringContainsString('Olá, Mariana!', $m->conteudo);
        $this->assertStringContainsString($o->numero, $m->conteudo);
        $this->assertStringContainsString('Total aprovado: R$ 1.000,00', str_replace("\u{a0}", ' ', $m->conteudo));
        $this->assertStringContainsString('Saldo a pagar: R$ 600,00', str_replace("\u{a0}", ' ', $m->conteudo));
        $this->assertStringContainsString('Não é documento fiscal', $m->conteudo);
        $this->assertStringNotContainsString('NOTA-INTERNA', $m->conteudo);
        $this->assertStringNotContainsString('DIAGNOSTICO-INTERNO', $m->conteudo);
        $this->assertTrue(LinkCliente::where('token_hash', LinkCliente::hash($m->segredo_link))->sole()->valido());

        // O link do portal abre a OS somente leitura sem sessão e sem dados internos.
        auth()->logout();
        $this->get(route('portal.impressao', $m->segredo_link))->assertOk()->assertSee($o->numero)->assertDontSee('NOTA-INTERNA-RECEPCAO')
            ->assertDontSee('Enviar por WhatsApp');

        // Job real com canal capturado: o texto enviado leva /c/{token}/os e marca como enviado.
        $capturado = new class implements Mensageiro {
            public array $enviados = [];
            public function canal(): string { return 'simulado'; }
            public function enviar(string $destino, string $texto): string { $this->enviados[] = [$destino, $texto]; return 'SIM-1'; }
        };
        $token = $m->segredo_link;
        (new \App\Jobs\EnviarMensagem($m->id))->handle($capturado);
        $this->assertStringContainsString('/c/'.$token.'/os', $capturado->enviados[0][1]);
        $this->assertStringNotContainsString(Mensagens::MARCADOR_LINK, $capturado->enviados[0][1]);
        $this->assertSame('enviado', $m->fresh()->status);
        $this->assertNull($m->fresh()->segredo_link);
    }

    public function test_canal_simulado_nao_envia_nada_real_e_aparece_na_aba_com_status(): void
    {
        $o = $this->ordemAprovada();
        $this->assertSame('simulado', config('oficina.integracoes'));
        $this->entrarComo($this->recepcao)->post(route('ordens.os.whatsapp', $o), ['canal' => 'sistema'])->assertSessionHasNoErrors();
        $m = MessageDelivery::where('ordem_id', $o->id)->where('tipo', 'documento_os')->sole();
        $this->assertSame('enviado', $m->status); // fila sync em teste; simulador não contata ninguém
        $this->assertStringStartsWith('SIM-MSG-', $m->id_provedor);
        $this->entrarComo($this->recepcao)->get(route('ordens.show', $o).'?aba=documentos')->assertOk()
            ->assertSee('OS enviada por WhatsApp')->assertSee('canal simulado (ninguém foi contatado)')->assertSee('Enviar por WhatsApp');
    }

    public function test_falha_aparece_e_reenvio_gera_link_novo_para_documento_os(): void
    {
        $o = $this->ordemAprovada();
        Cliente::whereKey($o->cliente_id)->update(['telefone' => '61991230000']); // simulador: final 0000 = provedor indisponível
        Queue::fake();
        $this->entrarComo($this->recepcao)->post(route('ordens.os.whatsapp', $o), ['canal' => 'sistema'])->assertSessionHasNoErrors();
        $m = MessageDelivery::where('tipo', 'documento_os')->sole();
        $m->update(['status' => 'falhou', 'segredo_link' => null]); // estado após falha definitiva (job->failed)
        app(Mensagens::class)->reenviar($m->fresh(), $this->recepcao);
        $this->assertSame('pendente', $m->fresh()->status);
        $this->assertNotNull($m->fresh()->segredo_link);
        $this->assertTrue(LinkCliente::where('token_hash', LinkCliente::hash($m->fresh()->segredo_link))->sole()->valido());
    }

    public function test_mecanico_e_outra_unidade_recebem_403(): void
    {
        $o = $this->ordemAprovada();
        $this->entrarComo($this->mecanico)->post(route('ordens.os.whatsapp', $o), ['canal' => 'sistema'])->assertForbidden();
        $this->entrarComo($this->mecanico)->post(route('ordens.os.whatsapp', $o), ['canal' => 'manual'])->assertForbidden();
        $outra = $this->usuario(Papel::Recepcao, Unidade::factory()->create());
        $this->entrarComo($outra)->post(route('ordens.os.whatsapp', $o), ['canal' => 'sistema'])->assertForbidden();
        $this->assertSame(0, MessageDelivery::where('tipo', 'documento_os')->count());
        $this->assertSame(0, AuditEvent::where('acao', 'os_enviada_whatsapp')->count());
    }

    public function test_cliente_sem_telefone_retorna_erro_de_validacao(): void
    {
        $o = $this->ordemAprovada();
        Cliente::whereKey($o->cliente_id)->update(['telefone' => '']);
        $links = LinkCliente::count();
        $this->entrarComo($this->recepcao)->from(route('ordens.show', $o))->post(route('ordens.os.whatsapp', $o), ['canal' => 'sistema'])
            ->assertRedirect(route('ordens.show', $o))->assertSessionHasErrors('telefone');
        $this->entrarComo($this->recepcao)->post(route('ordens.os.whatsapp', $o), ['canal' => 'manual'])->assertSessionHasErrors('telefone');
        $this->assertSame(0, MessageDelivery::where('tipo', 'documento_os')->count());
        $this->assertSame($links, LinkCliente::count());
    }

    public function test_manual_redireciona_para_wa_me_com_texto_codificado_cria_link_e_audita_sem_telefone_completo(): void
    {
        $o = $this->ordemAprovada();
        $links = LinkCliente::count();
        $r = $this->entrarComo($this->recepcao)->post(route('ordens.os.whatsapp', $o), ['canal' => 'manual']);
        $destino = $r->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/5561991234567?text=', $destino);
        $texto = rawurldecode(substr($destino, strpos($destino, '?text=') + 6));
        $this->assertStringNotContainsString(' ', substr($destino, strpos($destino, '?text=')));
        $this->assertStringContainsString('Olá, Mariana!', $texto);
        $this->assertMatchesRegularExpression('#/c/([A-Za-z0-9]{48})/os#', $texto);
        preg_match('#/c/([A-Za-z0-9]{48})/os#', $texto, $t);
        $this->assertSame($links + 1, LinkCliente::count());
        $this->assertSame(0, MessageDelivery::where('tipo', 'documento_os')->count());
        auth()->logout();
        $this->get(route('portal.impressao', $t[1]))->assertOk();

        $ev = AuditEvent::where('acao', 'os_enviada_whatsapp')->sole();
        $this->assertSame('manual', $ev->depois['canal']);
        $this->assertSame('4567', $ev->depois['telefone_final']);
        $this->assertStringNotContainsString('991234567', json_encode($ev->depois));
    }

    public function test_auditoria_do_envio_pelo_sistema_e_get_nao_gera_link(): void
    {
        $o = $this->ordemAprovada();
        $links = LinkCliente::count();
        $this->entrarComo($this->recepcao)->get(route('ordens.impressao', $o))->assertOk()->assertSee('Enviar por WhatsApp')->assertSee('Abrir no meu WhatsApp');
        $this->entrarComo($this->recepcao)->get(route('ordens.show', $o))->assertOk()->assertSee('Enviar por WhatsApp');
        $this->assertSame($links, LinkCliente::count());
        $this->entrarComo($this->recepcao)->get('/ordens/'.$o->id.'/os/whatsapp')->assertStatus(405);

        $this->entrarComo($this->recepcao)->post(route('ordens.os.whatsapp', $o), ['canal' => 'sistema']);
        $ev = AuditEvent::where('acao', 'os_enviada_whatsapp')->sole();
        $this->assertSame('sistema', $ev->depois['canal']);
        $this->assertSame($this->recepcao->id, $ev->ator_id);
        $this->assertNotNull($ev->depois['mensagem_id']);
        $this->assertStringNotContainsString('991234567', json_encode($ev->depois));
    }
}
