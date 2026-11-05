<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Integracoes\Pix\SimuladorPix;
use App\Jobs\EnviarWebhookSimulado;
use App\Models\Cobranca;
use App\Models\IntegrationEvent;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\Recebedor;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Execucao;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PagamentoEntregaTest extends TestCase
{
    private Unidade $unidade;
    private User $gerente;
    private User $recepcao;
    private User $mecanico;
    private User $dono;
    private Ordem $ordem;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado', 'oficina.pix.webhook_segredo' => 'segredo-teste', 'oficina.pix.permite_simular_pagamento' => true]);
        $this->unidade = Unidade::factory()->create(['codigo' => 'ASN']);
        $this->dono = User::factory()->proprietario()->create();
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
        Recebedor::create(['unidade_id' => $this->unidade->id, 'provedor' => 'simulado', 'identificador_conta' => 'conta-oficial-asn', 'descricao' => 'Conta PJ', 'ativo' => true, 'criado_por' => $this->dono->id]);
        $this->ordem = $this->ordemAprovada(48000);
    }

    private function ordemAprovada(int $centavos, string $placa = 'ABC1D23'): Ordem
    {
        $o = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana', 'telefone' => '61991234567', 'placa' => $placa, 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->mecanico);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $this->gerente, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => (string) ($centavos / 100)]]);
        $s->enviar($v, $this->gerente);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');

        return $o->fresh();
    }

    private function prontaParaEntrega(Ordem $o): void
    {
        $e = app(Execucao::class);
        $e->iniciar($o, $this->mecanico);
        foreach ($o->orcamentoAprovado->itens as $i) {
            $e->concluirItem($o, $i->id, $this->mecanico);
        }
        $e->marcarPronto($o, $this->mecanico);
    }

    private function webhook(string $txid, string $eventoId, ?string $segredo = 'segredo-teste')
    {
        $corpo = json_encode(['evento_id' => $eventoId, 'tipo' => 'pix.pago', 'txid' => $txid]);

        return $this->call('POST', '/webhooks/pix/simulado', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ASSINATURA' => hash_hmac('sha256', $corpo, $segredo)], $corpo);
    }

    public function test_cobranca_usa_conta_oficial_e_pedido_repetido_nao_duplica(): void
    {
        $c1 = app(Financeiro::class)->gerarCobranca($this->ordem, $this->recepcao);
        $c2 = app(Financeiro::class)->gerarCobranca($this->ordem, $this->recepcao);
        $this->assertSame($c1->id, $c2->id);
        $this->assertSame(['pendente', 48000, 'conta-oficial-asn'], [$c1->status, $c1->valor_centavos, $c1->recebedor->identificador_conta]);
        $this->assertSame(1, Cobranca::count());
        $this->assertSame('conta-oficial-asn', DB::table('simulador_pix')->where('txid', $c1->txid)->value('conta'));
    }

    public function test_gerente_e_recepcao_nao_alteram_conta_recebedora(): void
    {
        foreach ([$this->gerente, $this->recepcao] as $u) {
            $this->entrarComo($u)->post('/configuracoes/recebedores', ['unidade_id' => $this->unidade->id, 'identificador_conta' => 'pix-pessoal', 'descricao' => 'minha'])->assertForbidden();
        }
        $this->assertSame(0, Recebedor::where('identificador_conta', 'pix-pessoal')->count());
        $this->entrarComo($this->dono)->post('/configuracoes/recebedores', ['unidade_id' => $this->unidade->id, 'identificador_conta' => 'conta-nova', 'descricao' => 'Nova'])->assertRedirect();
        $this->assertSame('conta-nova', Recebedor::where('unidade_id', $this->unidade->id)->where('ativo', true)->sole()->identificador_conta);
        $this->assertDatabaseHas('audit_events', ['acao' => 'recebedor_alterado', 'ator_id' => $this->dono->id]);
    }

    public function test_sem_conta_oficial_nao_gera_cobranca(): void
    {
        Recebedor::query()->update(['ativo' => false]);
        $this->expectException(ValidationException::class);
        app(Financeiro::class)->gerarCobranca($this->ordem, $this->recepcao);
    }

    public function test_webhook_sem_assinatura_valida_e_rejeitado_e_nada_e_creditado(): void
    {
        $c = app(Financeiro::class)->gerarCobranca($this->ordem, $this->recepcao);
        app(SimuladorPix::class)->simularPagamento($c->txid);
        $this->webhook($c->txid, 'evt-1', 'segredo-errado')->assertStatus(401);
        $this->assertSame(0, IntegrationEvent::count());
        $this->assertSame(0, Pagamento::count());
    }

    public function test_webhook_valido_confirma_pela_consulta_e_repeticao_nao_duplica(): void
    {
        $c = app(Financeiro::class)->gerarCobranca($this->ordem, $this->recepcao);
        $evt = app(SimuladorPix::class)->simularPagamento($c->txid);
        $this->webhook($c->txid, $evt['evento_id'])->assertOk();
        $this->webhook($c->txid, $evt['evento_id'])->assertOk(); // repetição
        $this->webhook($c->txid, 'outro-id-mesmo-pagamento')->assertOk();

        $this->assertSame(1, Pagamento::count());
        $p = Pagamento::sole();
        $this->assertSame(['pix', 'confirmado', 48000], [$p->meio, $p->status, $p->valor_centavos]);
        $this->assertSame('paga', $c->fresh()->status);
        $this->assertSame(0, app(Financeiro::class)->resumo($this->ordem)['saldo']);
        $this->assertSame(['confirmado', 'duplicado'], IntegrationEvent::orderBy('id')->pluck('resultado')->all());
    }

    public function test_notificacao_autentica_sem_pagamento_no_provedor_nao_credita(): void
    {
        $c = app(Financeiro::class)->gerarCobranca($this->ordem, $this->recepcao);
        $this->webhook($c->txid, 'evt-falso')->assertOk();
        $this->assertSame(0, Pagamento::count());
        $this->assertSame('pendente', $c->fresh()->status);
    }

    public function test_valor_ou_conta_divergente_fica_em_revisao_e_nao_quita(): void
    {
        $f = app(Financeiro::class);
        $c = $f->gerarCobranca($this->ordem, $this->recepcao);
        app(SimuladorPix::class)->simularPagamento($c->txid, 40000);
        $this->assertSame('em_revisao', $f->conciliarCobranca($c->txid));
        $p = Pagamento::sole();
        $this->assertStringContainsString('valor pago diferente', $p->motivo_revisao);
        $this->assertSame(48000, $f->resumo($this->ordem)['saldo']);

        $o2 = $this->ordemAprovada(10000, 'XYZ9876');
        $c2 = $f->gerarCobranca($o2, $this->recepcao);
        DB::table('simulador_pix')->where('txid', $c2->txid)->update(['conta' => 'conta-de-terceiro']);
        app(SimuladorPix::class)->simularPagamento($c2->txid);
        $this->assertSame('em_revisao', $f->conciliarCobranca($c2->txid));
        $this->assertStringContainsString('conta recebedora diferente', Pagamento::where('ordem_id', $o2->id)->sole()->motivo_revisao);

        try {
            $f->aceitarRevisao($p, $this->recepcao, 'conferido');
            $this->fail('recepção não resolve divergência');
        } catch (AuthorizationException) {
        }
        $f->aceitarRevisao($p, $this->gerente, 'Cliente pagou parte; conferido no extrato');
        $this->assertSame(8000, $f->resumo($this->ordem)['saldo']);
    }

    public function test_pix_nao_pode_ser_registrado_manualmente_nem_por_comprovante(): void
    {
        $this->entrarComo($this->recepcao)->post("/ordens/{$this->ordem->numero}/recebimentos", ['meio' => 'pix', 'valor_recebido' => '480,00'])->assertSessionHasErrors('meio');
        $this->expectException(\Illuminate\Database\QueryException::class);
        Pagamento::create(['ordem_id' => $this->ordem->id, 'unidade_id' => $this->unidade->id, 'meio' => 'pix', 'valor_centavos' => 100, 'status' => 'confirmado', 'recebido_em' => now(), 'registrado_por' => $this->recepcao->id]);
    }

    public function test_parcial_em_dinheiro_e_restante_por_pix_sem_excesso(): void
    {
        $f = app(Financeiro::class);
        try {
            $f->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 48001);
            $this->fail('excesso deve ser recusado');
        } catch (ValidationException) {
        }
        $f->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 20000, 'entrada');
        $c = $f->gerarCobranca($this->ordem, $this->recepcao);
        $this->assertSame(28000, $c->valor_centavos);
        app(SimuladorPix::class)->simularPagamento($c->txid);
        $f->conciliarCobranca($c->txid);
        $this->assertSame(0, $f->resumo($this->ordem)['saldo']);
        $this->expectException(ValidationException::class);
        $f->gerarCobranca($this->ordem, $this->recepcao);
    }

    public function test_nova_versao_aprovada_cancela_cobranca_antiga_e_pagamento_tardio_vai_para_revisao(): void
    {
        $f = app(Financeiro::class);
        $s = app(Orcamentos::class);
        app(Execucao::class)->iniciar($this->ordem, $this->mecanico);
        $c = $f->gerarCobranca($this->ordem, $this->recepcao);
        $v2 = $s->salvarRascunho($this->ordem, $this->gerente, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '480'], ['descricao' => 'Extra', 'quantidade' => '1', 'preco' => '100']]);
        $s->enviar($v2, $this->gerente);
        $s->decidir($v2->id, $this->ordem->id, 'aprovado', 'link_cliente');
        $this->assertSame('cancelada', $c->fresh()->status);

        DB::table('simulador_pix')->where('txid', $c->txid)->update(['status' => 'ativa']); // banco pagou antes de processar o cancelamento
        app(SimuladorPix::class)->simularPagamento($c->txid);
        $this->assertSame('em_revisao', $f->conciliarCobranca($c->txid));
        $this->assertSame(58000, $f->resumo($this->ordem)['saldo']);
    }

    public function test_devolucao_exige_permissao_limite_e_recalcula_saldo(): void
    {
        $f = app(Financeiro::class);
        $p = $f->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 48000);
        try {
            $f->devolver($p, $this->recepcao, 1000, 'teste');
            $this->fail();
        } catch (AuthorizationException) {
        }
        try {
            $f->devolver($p, $this->gerente, 48001, 'excesso');
            $this->fail();
        } catch (ValidationException) {
        }
        $f->devolver($p, $this->gerente, 5000, 'Peça não utilizada');
        $this->assertSame(5000, $f->resumo($this->ordem)['saldo']);
        $this->assertDatabaseHas('audit_events', ['acao' => 'devolucao_registrada', 'motivo' => 'Peça não utilizada', 'ator_id' => $this->gerente->id]);
    }

    public function test_entrega_com_saldo_exige_gerente_motivo_e_gera_excecao(): void
    {
        $f = app(Financeiro::class);
        try {
            $f->entregar($this->ordem, $this->recepcao);
            $this->fail('não está pronta');
        } catch (ValidationException) {
        }
        $this->prontaParaEntrega($this->ordem);
        $this->entrarComo($this->recepcao)->post("/ordens/{$this->ordem->numero}/entrega")->assertForbidden();
        $this->entrarComo($this->gerente)->post("/ordens/{$this->ordem->numero}/entrega", ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->entrarComo($this->gerente)->post("/ordens/{$this->ordem->numero}/entrega", ['motivo' => 'Cliente frotista, paga no dia 10'])->assertRedirect();

        $o = $this->ordem->fresh('entrega');
        $this->assertSame(StatusOrdem::Entregue, $o->status);
        $this->assertTrue($o->entrega->excecao);
        $this->assertSame(48000, $o->entrega->saldo_centavos);
        $this->assertDatabaseHas('audit_events', ['acao' => 'entrega_excecao', 'motivo' => 'Cliente frotista, paga no dia 10']);
        $this->expectException(ValidationException::class);
        $f->entregar($o, $this->gerente, 'de novo');
    }

    public function test_entrega_quitada_pela_recepcao_e_normal(): void
    {
        app(Financeiro::class)->registrarManual($this->ordem, $this->recepcao, 'cartao_externo', 48000, 'NSU 123');
        $this->prontaParaEntrega($this->ordem);
        $this->entrarComo($this->recepcao)->post("/ordens/{$this->ordem->numero}/entrega")->assertRedirect();
        $this->assertFalse($this->ordem->fresh('entrega')->entrega->excecao);
    }

    public function test_fechamento_de_caixa_por_gerente_registra_diferenca_e_nao_repete(): void
    {
        $f = app(Financeiro::class);
        $f->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 30000);
        $hoje = now('America/Sao_Paulo')->format('Y-m-d');
        $this->entrarComo($this->recepcao)->post('/caixa/fechar', ['unidade' => $this->unidade->id, 'data' => $hoje, 'contado' => '300,00'])->assertForbidden();
        $this->entrarComo($this->gerente)->get('/caixa')->assertOk()->assertSee('R$ 300,00');
        $this->entrarComo($this->gerente)->post('/caixa/fechar', ['unidade' => $this->unidade->id, 'data' => $hoje, 'contado' => '290,00', 'observacao' => 'Faltou troco'])->assertRedirect();
        $this->assertNotNull(Pagamento::sole()->fechamento_id);
        $this->assertDatabaseHas('audit_events', ['acao' => 'caixa_fechado', 'motivo' => 'Faltou troco']);
        $this->entrarComo($this->gerente)->post('/caixa/fechar', ['unidade' => $this->unidade->id, 'data' => $hoje, 'contado' => '300,00'])->assertSessionHasErrors('data');
        $outra = Unidade::factory()->create();
        $this->entrarComo($this->gerente)->get("/caixa?unidade={$outra->id}")->assertForbidden();
    }

    public function test_conciliacao_recupera_webhook_perdido_e_expira_vencidas(): void
    {
        $f = app(Financeiro::class);
        $c = $f->gerarCobranca($this->ordem, $this->recepcao);
        app(SimuladorPix::class)->simularPagamento($c->txid); // webhook nunca chegou
        $this->artisan('oficina:conciliar-pix')->assertSuccessful();
        $this->assertSame('confirmado', Pagamento::sole()->status);

        $o2 = $this->ordemAprovada(10000, 'XYZ9876');
        $c2 = $f->gerarCobranca($o2, $this->recepcao);
        DB::table('simulador_pix')->where('txid', $c2->txid)->update(['expira_em' => now()->subMinute()]);
        $this->artisan('oficina:conciliar-pix')->assertSuccessful();
        $this->assertSame('expirada', $c2->fresh()->status);
    }

    public function test_modo_real_sem_adaptador_falha_explicitamente(): void
    {
        config(['oficina.integracoes' => 'real']);
        $this->app->forgetInstance(\App\Integracoes\Pix\ProvedorPix::class);
        $c = app(Financeiro::class)->gerarCobranca($this->ordem, $this->recepcao);
        $this->assertSame('falha', $c->status);
        $this->assertStringContainsString('não configurada', $c->erro);
        $this->assertNull($c->txid);
        $this->entrarComo($this->recepcao)->post("/ordens/{$this->ordem->numero}/cobrancas/{$c->id}/simular")->assertNotFound();
    }

    public function test_simulacao_envia_webhook_assinado_pela_fila(): void
    {
        $c = app(Financeiro::class)->gerarCobranca($this->ordem, $this->recepcao);
        Queue::fake();
        $this->entrarComo($this->recepcao)->post("/ordens/{$this->ordem->numero}/cobrancas/{$c->id}/simular")->assertRedirect();
        Queue::assertPushed(EnviarWebhookSimulado::class);

        Http::fake(['*' => Http::response(['recebido' => true])]);
        (new EnviarWebhookSimulado($c->txid))->handle(app(SimuladorPix::class));
        Http::assertSent(fn ($r) => hash_equals(hash_hmac('sha256', $r->body(), 'segredo-teste'), $r->header('X-Assinatura')[0]));
    }

    public function test_simulador_de_pagamento_desligado_por_padrao(): void
    {
        config(['oficina.pix.permite_simular_pagamento' => false]);
        $c = app(Financeiro::class)->gerarCobranca($this->ordem, $this->recepcao);
        $this->entrarComo($this->recepcao)->post("/ordens/{$this->ordem->numero}/cobrancas/{$c->id}/simular")->assertNotFound();
        $this->entrarComo($this->recepcao)->get("/ordens/{$this->ordem->numero}")->assertDontSee('Simular pagamento');
    }

    public function test_recebimento_manual_recusado_apos_fechamento_do_caixa(): void
    {
        app(Financeiro::class)->fecharCaixa($this->unidade->id, now('America/Sao_Paulo')->format('Y-m-d'), $this->gerente, 0, null);
        $this->expectException(ValidationException::class);
        app(Financeiro::class)->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 1000);
    }

    public function test_decisao_em_ordem_cancelada_e_rejeitada(): void
    {
        $o = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Z', 'telefone' => '61991239999', 'placa' => 'ZZZ9999', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->mecanico);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $this->gerente, [['descricao' => 'S', 'quantidade' => '1', 'preco' => '10']]);
        $s->enviar($v, $this->gerente);
        app(Ordens::class)->cancelar($o, 'Cliente desistiu', $this->gerente);
        $this->expectException(ValidationException::class);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
    }

    public function test_cancelamento_da_ordem_nao_estorna_recebimento(): void
    {
        app(Financeiro::class)->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 10000);
        app(Ordens::class)->cancelar($this->ordem, 'Cliente desistiu', $this->gerente);
        $this->assertSame(1, Pagamento::count());
        $this->assertSame(0, \App\Models\Devolucao::count());
    }

    public function test_portal_mostra_cobranca_pendente(): void
    {
        $c = app(Financeiro::class)->gerarCobranca($this->ordem, $this->recepcao);
        $token = app(Orcamentos::class)->novoLink($this->ordem, $this->recepcao);
        $this->get("/c/{$token}")->assertOk()->assertSee($c->copia_e_cola)->assertSee('R$ 480,00');
    }

    public function test_valor_recusado_em_um_formulario_nao_contamina_outro(): void
    {
        $this->entrarComo($this->recepcao)->from("/ordens/{$this->ordem->numero}")
            ->post("/ordens/{$this->ordem->numero}/recebimentos", ['meio' => 'dinheiro', 'valor_recebido' => '999,00'])->assertSessionHasErrors('valor');
        $html = $this->get("/ordens/{$this->ordem->numero}")->getContent();
        $this->assertStringNotContainsString('id="valor-cobranca" name="valor_cobranca" type="text" value="999,00"', $html);
        $this->post("/ordens/{$this->ordem->numero}/cobrancas", ['valor_cobranca' => ''])->assertRedirect();
        $this->assertSame(48000, Cobranca::sole()->valor_centavos);
    }
}
