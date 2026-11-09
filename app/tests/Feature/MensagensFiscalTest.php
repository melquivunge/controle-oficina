<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Jobs\EnviarMensagem;
use App\Jobs\ProcessarDocumentoFiscal;
use App\Models\FiscalDocument;
use App\Models\MessageDelivery;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Fiscal;
use App\Services\Mensagens;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MensagensFiscalTest extends TestCase
{
    private Unidade $unidade;
    private User $recepcao;
    private User $gerente;
    private User $mecanico;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado', 'oficina.fiscal.simular_indisponivel' => false]);
        Storage::fake('local');
        $this->unidade = Unidade::factory()->create();
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
    }

    private function entrada(string $telefone = '61991234567'): Ordem
    {
        return app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana Costa', 'telefone' => $telefone, 'placa' => 'ABC1D23', 'motivo' => 'x']);
    }

    private function aprovada(string $preco = '480'): Ordem
    {
        $o = $this->entrada();
        app(Ordens::class)->iniciarDiagnostico($o, $this->mecanico);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $this->gerente, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => $preco]]);
        $s->enviar($v, $this->gerente);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');

        return $o->fresh();
    }

    public function test_entrada_envia_mensagem_pelo_canal_e_token_nao_fica_em_claro(): void
    {
        $o = $this->entrada();
        $m = MessageDelivery::where('ordem_id', $o->id)->sole();
        $this->assertSame(['entrada', 'simulado', 'enviado', 1], [$m->tipo, $m->canal, $m->status, $m->tentativas]);
        $this->assertStringContainsString(Mensagens::MARCADOR_LINK, $m->conteudo);
        $this->assertNull(DB::table('message_deliveries')->where('id', $m->id)->value('segredo_link'), 'token apagado após envio');
        $this->assertNotNull($m->id_provedor);
    }

    public function test_outbox_grava_pendente_na_transacao_e_varredura_redespacha(): void
    {
        Queue::fake();
        $o = $this->entrada();
        $m = MessageDelivery::where('ordem_id', $o->id)->sole();
        $this->assertSame('pendente', $m->status);
        $cifrado = DB::table('message_deliveries')->where('id', $m->id)->value('segredo_link');
        $this->assertNotNull($cifrado);
        $this->assertSame(48, strlen($m->segredo_link));
        $this->assertStringNotContainsString($m->segredo_link, $cifrado, 'token cifrado no banco');

        $this->travel(6)->minutes();
        $this->artisan('oficina:reprocessar-pendencias')->assertSuccessful();
        Queue::assertPushed(EnviarMensagem::class, 2);
    }

    public function test_falha_do_canal_fica_visivel_nao_conta_como_entrega_e_pode_ser_reenviada(): void
    {
        Queue::fake();
        $o = $this->entrada('61988880000'); // simulador: indisponível
        $m = MessageDelivery::where('ordem_id', $o->id)->sole();
        $job = new EnviarMensagem($m->id);
        try {
            $job->handle(app(\App\Integracoes\Mensagens\Mensageiro::class));
            $this->fail('deveria falhar');
        } catch (\RuntimeException) {
        }
        $job->failed(new \RuntimeException('Simulação: provedor de mensagens indisponível.'));
        $m->refresh();
        $this->assertSame(['falhou', 1], [$m->status, $m->tentativas]);
        $this->assertNull(DB::table('message_deliveries')->where('id', $m->id)->value('segredo_link'), 'token não fica retido após falha');
        $this->assertNull($m->enviado_em);
        $this->assertDatabaseHas('audit_events', ['acao' => 'mensagem_falhou']);

        $this->entrarComo($this->mecanico)->post("/ordens/{$o->numero}/mensagens/{$m->id}/reenviar")->assertForbidden();
        $this->entrarComo($this->recepcao)->post("/ordens/{$o->numero}/mensagens/{$m->id}/reenviar")->assertRedirect();
        $this->assertSame('pendente', $m->fresh()->status);
        Queue::assertPushed(EnviarMensagem::class);
    }

    public function test_mensagens_de_orcamento_cobranca_e_conclusao_sao_geradas(): void
    {
        $o = $this->aprovada();
        $this->assertSame(['entrada', 'orcamento'], MessageDelivery::where('ordem_id', $o->id)->orderBy('id')->pluck('tipo')->all());
    }

    public function test_emissao_fiscal_simulada_autorizada_preserva_numero_e_documento_sem_validade(): void
    {
        $o = $this->aprovada();
        $doc = app(Fiscal::class)->solicitar($o, $this->recepcao)->first()->fresh();
        $this->assertSame('autorizado', $doc->status);
        $this->assertStringStartsWith('SIM-', $doc->numero);
        Storage::disk('local')->assertExists($doc->arquivo);
        $this->assertStringContainsString('SEM VALIDADE FISCAL', Storage::disk('local')->get($doc->arquivo));
        $this->assertStringContainsString('SEM VALIDADE FISCAL', $this->entrarComo($this->recepcao)->get("/ordens/{$o->numero}/fiscal/{$doc->id}")->assertOk()->streamedContent());
        $this->entrarComo($this->usuario(Papel::Gerente))->get("/ordens/{$o->numero}/fiscal/{$doc->id}")->assertForbidden();
        $o->forceFill(['mecanico_id' => $this->mecanico->id])->save();
        $this->entrarComo($this->mecanico)->get("/ordens/{$o->numero}/fiscal/{$doc->id}")->assertForbidden();

        // Reprocessar o mesmo documento não emite de novo (consulta pela chave).
        app(Fiscal::class)->processar($doc->id);
        $this->assertSame(1, DB::table('simulador_fiscal')->count());
        $this->expectException(ValidationException::class);
        app(Fiscal::class)->solicitar($o, $this->recepcao);
    }

    public function test_fiscal_independe_de_pagamento_e_mecanico_nao_emite(): void
    {
        $o = $this->aprovada();
        $this->assertSame(0, \App\Models\Pagamento::count());
        $this->expectException(AuthorizationException::class);
        app(Fiscal::class)->solicitar($o, $this->mecanico);
    }

    public function test_rejeicao_preservada_e_nova_tentativa_gera_novo_registro(): void
    {
        $o = $this->aprovada('480,13');
        $doc = app(Fiscal::class)->solicitar($o, $this->recepcao)->first()->fresh();
        $this->assertSame('rejeitado', $doc->status);
        $this->assertStringContainsString('rejeição de teste', $doc->mensagem);
        $novo = app(Fiscal::class)->reprocessar($doc, $this->recepcao);
        $this->assertNotSame($doc->id, $novo->id);
        $this->assertSame(2, FiscalDocument::where('ordem_id', $o->id)->count());
        $this->assertSame('rejeitado', $doc->fresh()->status);
    }

    public function test_indisponibilidade_fica_visivel_e_e_recuperavel_sem_duplicar(): void
    {
        $o = $this->aprovada();
        config(['oficina.fiscal.simular_indisponivel' => true]);
        Queue::fake();
        $doc = app(Fiscal::class)->solicitar($o, $this->recepcao)->first();
        try {
            app(Fiscal::class)->processar($doc->id);
            $this->fail();
        } catch (\RuntimeException) {
        }
        $doc->refresh();
        $this->assertSame(['pendente', 1], [$doc->status, $doc->tentativas]);
        $this->assertStringContainsString('indisponível', $doc->mensagem);
        $this->entrarComo($this->recepcao)->get("/ordens/{$o->numero}")->assertSee('indisponível')->assertSee('Tentar de novo');

        config(['oficina.fiscal.simular_indisponivel' => false]);
        app(Fiscal::class)->processar($doc->id);
        app(Fiscal::class)->processar($doc->id);
        $this->assertSame('autorizado', $doc->fresh()->status);
        $this->assertSame(1, DB::table('simulador_fiscal')->count());
    }

    public function test_cancelamento_fiscal_exige_gerente_e_justificativa(): void
    {
        $o = $this->aprovada();
        $doc = app(Fiscal::class)->solicitar($o, $this->recepcao)->first()->fresh();
        $this->entrarComo($this->recepcao)->post("/ordens/{$o->numero}/fiscal/{$doc->id}/cancelar", ['motivo' => 'Erro de digitação no serviço'])->assertForbidden();
        $this->entrarComo($this->gerente)->post("/ordens/{$o->numero}/fiscal/{$doc->id}/cancelar", ['motivo' => 'curto'])->assertSessionHasErrorsIn('fiscal_cancelar', 'motivo');
        $this->entrarComo($this->gerente)->post("/ordens/{$o->numero}/fiscal/{$doc->id}/cancelar", ['motivo' => 'Erro de digitação no serviço'])->assertRedirect();
        $this->assertSame('cancelado', $doc->fresh()->status);
        $this->assertSame($doc->numero, $doc->fresh()->numero, 'número preservado');
    }

    /** Regressão: justificativa inválida não pode falhar em silêncio (erro escondido em <details> fechado, sem alerta). */
    public function test_cancelamento_com_justificativa_curta_mostra_erro_visivel_e_mantem_formulario_aberto(): void
    {
        $o = $this->aprovada();
        $doc = app(Fiscal::class)->solicitar($o, $this->recepcao)->first()->fresh();
        $show = "/ordens/{$o->numero}?aba=documentos";
        $r = $this->entrarComo($this->gerente)->from($show)->post("/ordens/{$o->numero}/fiscal/{$doc->id}/cancelar", ['motivo' => 'curto']);
        $r->assertRedirect($show.'#fiscal-'.$doc->id);
        $r->assertSessionHasErrorsIn('fiscal_cancelar', 'motivo');
        $this->assertFalse(session('errors')->getBag('default')->has('motivo'), 'erro não vaza para os outros formulários com campo motivo');
        // Sessão com serialização JSON: no ciclo real os erros chegam como array e são remontados ao iniciar a sessão.
        // No harness o objeto ViewErrorBag fica em memória e a remontagem o esvazia; por isso injetamos o formato serializado
        // (o ciclo HTTP completo é coberto no E2E docs/evidencias/e2e/fiscal.cjs).
        $bag = session('errors')->getBag('fiscal_cancelar');
        $html = $this->withSession(['errors' => ['fiscal_cancelar' => ['format' => $bag->getFormat(), 'messages' => $bag->getMessages()]],
            'fiscal_cancelar' => $doc->id, '_old_input' => ['motivo_fiscal' => 'curto']])->get($show)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<details[^>]*\bopen\b[^>]*data-fiscal-cancelar="'.$doc->id.'"/', $html, 'formulário de cancelamento reaberto');
        $this->assertStringContainsString('fiscal-motivo-'.$doc->id, $html, 'aba documentos renderizada');
        $this->assertStringContainsString('Documento não cancelado', $html);
        $this->assertSame(1, substr_count($html, 'pelo menos 15 caracteres'), 'erro só no formulário fiscal, não no cancelamento da ordem');
        $this->assertSame('autorizado', $doc->fresh()->status);
    }

    /** Regressão: após cancelar, o documento aberto precisa mostrar o cancelamento (antes reexibia o documento como emitido). */
    public function test_documento_cancelado_exibe_carimbo_de_cancelamento_e_tela_nao_oferece_emitir_como_cancelar(): void
    {
        $o = $this->aprovada();
        $doc = app(Fiscal::class)->solicitar($o, $this->recepcao)->first()->fresh();
        $this->entrarComo($this->gerente)->post("/ordens/{$o->numero}/fiscal/{$doc->id}/cancelar", ['motivo' => 'Erro de digitação no serviço'])->assertRedirect();
        $conteudo = $this->entrarComo($this->recepcao)->get("/ordens/{$o->numero}/fiscal/{$doc->id}")->assertOk()->streamedContent();
        $this->assertStringContainsString('CANCELADO', $conteudo);
        $this->assertStringContainsString('Erro de digitação no serviço', $conteudo);
        $html = $this->entrarComo($this->gerente)->get("/ordens/{$o->numero}?aba=documentos")->getContent();
        $this->assertStringContainsString('Emitir NFS-e (serviços)', $html);
        $this->assertStringNotContainsString('>Emitir documento fiscal<', $html);
    }

    public function test_documento_simulado_e_a4_com_identidade_tomador_itens_e_tarja(): void
    {
        $o = $this->aprovada();
        $doc = app(Fiscal::class)->solicitar($o, $this->recepcao)->first()->fresh();
        $c = Storage::disk('local')->get($doc->arquivo);
        foreach (['size: A4', 'SEM VALIDADE FISCAL — SIMULAÇÃO', 'Tomador', 'Prestador', 'Código de verificação', 'Valor unitário', $o->cliente->nome, $doc->numero, $o->numero] as $t) {
            $this->assertStringContainsString($t, $c);
        }
        $this->assertStringNotContainsString('<script', $c);
    }

    public function test_status_fiscal_em_json_autorizado_e_leve(): void
    {
        Queue::fake();
        $o = $this->aprovada();
        $doc = app(Fiscal::class)->solicitar($o, $this->recepcao)->first()->fresh();
        $url = "/ordens/{$o->numero}/fiscal-status";
        $r = $this->entrarComo($this->recepcao)->getJson($url)->assertOk()->assertJson(['acompanhar' => true]);
        $this->assertStringContainsString('Processando', $r->json('html'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        app(Fiscal::class)->processar($doc->id);
        $r = $this->getJson($url)->assertOk()->assertJson(['acompanhar' => false]);
        $this->assertStringContainsString('Autorizado', $r->json('html'));
        $o->forceFill(['mecanico_id' => $this->mecanico->id])->save();
        $this->entrarComo($this->mecanico)->getJson($url)->assertForbidden();
        $outra = $this->usuario(Papel::Gerente);
        $this->entrarComo($outra)->getJson($url)->assertForbidden();
    }

    public function test_portal_lista_documento_e_link_de_outra_ordem_nao_baixa(): void
    {
        $o = $this->aprovada();
        $doc = app(Fiscal::class)->solicitar($o, $this->recepcao)->first()->fresh();
        $token = app(Orcamentos::class)->novoLink($o, $this->recepcao);
        $this->get("/c/{$token}")->assertOk()->assertSee($doc->numero)->assertSee('SEM VALIDADE FISCAL');
        $this->get("/c/{$token}/documentos/{$doc->id}")->assertOk();

        $outra = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Outro', 'telefone' => '61999990001', 'placa' => 'XYZ9876', 'motivo' => 'x']);
        $tokenOutra = app(Orcamentos::class)->novoLink($outra, $this->recepcao);
        $this->get("/c/{$tokenOutra}/documentos/{$doc->id}")->assertNotFound();
    }
}
