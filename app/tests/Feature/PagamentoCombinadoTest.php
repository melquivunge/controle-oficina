<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Integracoes\Pix\SimuladorPix;
use App\Models\AuditEvent;
use App\Models\CombinadoPagamento;
use App\Models\Entrega;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\Recebedor;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Combinados;
use App\Services\Entradas;
use App\Services\Execucao;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use App\Services\Pendencias;
use App\Support\Fuso;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Bloco B: combinado de pagamento do saldo (parcelas com vencimento), baixa derivada e entrega sem exceção. */
class PagamentoCombinadoTest extends TestCase
{
    private Unidade $unidade;
    private User $dono;
    private User $gerente;
    private User $recepcao;
    private User $mecanico;
    private Ordem $ordem;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado', 'oficina.pix.webhook_segredo' => 'segredo-teste', 'oficina.pix.permite_simular_pagamento' => true]);
        $this->travelTo(now()->setTimezone('America/Sao_Paulo')->setTime(10, 0)->utc()); // meio da manhã local: datas estáveis
        $this->unidade = Unidade::factory()->create(['codigo' => 'ASN']);
        $this->dono = User::factory()->proprietario()->create();
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
        Recebedor::create(['unidade_id' => $this->unidade->id, 'provedor' => 'simulado', 'identificador_conta' => 'conta-oficial-asn', 'descricao' => 'Conta PJ', 'ativo' => true, 'criado_por' => $this->dono->id]);
        $this->ordem = $this->ordemAprovada(100000);
    }

    private function ordemAprovada(int $centavos, ?Unidade $unidade = null, string $placa = 'ABC1D23'): Ordem
    {
        $unidade ??= $this->unidade;
        $o = app(Entradas::class)->registrar($this->dono, ['unidade_id' => $unidade->id, 'nome' => 'Mariana', 'telefone' => '61991234567', 'placa' => $placa, 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->dono);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $this->dono, [['descricao' => 'Jogo de rodas', 'quantidade' => '1', 'preco' => (string) ($centavos / 100)]]);
        $s->enviar($v, $this->dono);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');

        return $o->fresh();
    }

    private function prontaParaEntrega(Ordem $o): void
    {
        $e = app(Execucao::class);
        $e->iniciar($o, $this->dono);
        foreach ($o->orcamentoAprovado->itens as $i) {
            $e->concluirItem($o, $i->id, $this->dono);
        }
        $e->marcarPronto($o, $this->dono);
    }

    private function pagarPix(Ordem $o, int $centavos): void
    {
        $f = app(Financeiro::class);
        $c = $f->gerarCobranca($o, $this->recepcao, $centavos);
        app(SimuladorPix::class)->simularPagamento($c->txid);
        $this->assertSame('confirmado', $f->conciliarCobranca($c->txid));
    }

    private function hoje(int $dias = 0): string
    {
        return Fuso::exibir(now()->addDays($dias), 'Y-m-d');
    }

    /** Combinado do saldo restante: R$ 500 em 10 dias. */
    private function combinar500em10dias(?User $ator = null): CombinadoPagamento
    {
        return app(Combinados::class)->registrar($this->ordem, $ator ?? $this->gerente, [
            ['valor_centavos' => 50000, 'vencimento' => $this->hoje(10), 'meio_previsto' => 'dinheiro', 'observacao' => 'restante na retirada'],
        ]);
    }

    public function test_500_pix_hoje_e_500_em_10_dias_caixa_a_receber_vencida_e_baixa(): void
    {
        $this->pagarPix($this->ordem, 50000);
        // Combinado registrado pela tela (gerente), soma = saldo.
        $this->entrarComo($this->gerente)->post(route('combinados.registrar', $this->ordem), ['parcelas' => [
            ['valor' => '500,00', 'vencimento' => $this->hoje(10), 'meio' => 'dinheiro', 'observacao' => 'restante'],
            ['valor' => '', 'vencimento' => ''],
        ]])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, CombinadoPagamento::where('ordem_id', $this->ordem->id)->where('status', 'vigente')->count());
        $this->assertTrue(AuditEvent::where('acao', 'combinado_registrado')->where('entidade_id', $this->ordem->id)->exists());

        // Caixa do dia conta só o recebido (500 de Pix); A receber mostra 500 com vencimento.
        $caixa = $this->entrarComo($this->recepcao)->get(route('caixa.index', ['aba' => 'receber']))->assertOk();
        $caixa->assertSeeInOrder(['Pix confirmado pelo banco', 'R$ 500,00']);
        $caixa->assertSee('Próxima parcela')->assertSee('em '.now()->addDays(10)->setTimezone('America/Sao_Paulo')->format('d/m/Y'));
        $this->assertSame(50000, (int) app(Financeiro::class)->recebimentosDoDia($this->unidade->id, $this->hoje())->sum('valor_centavos'));
        $s = app(Combinados::class)->situacao($this->ordem);
        $this->assertSame('aberta', $s['parcelas'][0]->situacao);
        $this->assertSame(0, $s['vencido']);
        $this->assertCount(0, app(Combinados::class)->vencidas($this->gerente));

        // Após 11 dias: vencida em pendências, no serviço e destacada na OS e no caixa.
        $this->travel(11)->days();
        $vencidas = app(Combinados::class)->vencidas($this->gerente);
        $this->assertCount(1, $vencidas);
        $this->assertSame(50000, $vencidas[0]->emAbertoCentavos());
        $this->assertSame($this->ordem->id, $vencidas[0]->ordem->id);
        $pend = app(Pendencias::class)->listar($this->gerente)->where('tipo', 'pagamento_vencido');
        $this->assertCount(1, $pend);
        $this->assertSame('atencao', $pend->first()['gravidade']);
        $this->assertSame('Pagamento combinado vencido', $pend->first()['rotulo']);
        $this->entrarComo($this->gerente)->get(route('pendencias.index'))->assertOk()->assertSee('Pagamento combinado vencido')
            ->assertSee(route('ordens.show', [$this->ordem, 'aba' => 'pagamentos']), false);
        $this->entrarComo($this->gerente)->get(route('ordens.show', [$this->ordem, 'aba' => 'pagamentos']))->assertOk()
            ->assertSee('Pagamento combinado')->assertSee('Vencida');
        $this->entrarComo($this->recepcao)->get(route('caixa.index', ['aba' => 'receber']))->assertSee('parcela-vencida', false);

        // Paga parcial (dinheiro 200): continua vencida com 300 em aberto; paga o resto: some.
        app(Financeiro::class)->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 20000);
        $this->assertSame(30000, app(Combinados::class)->vencidas($this->gerente)->first()->emAbertoCentavos());
        app(Financeiro::class)->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 30000);
        $this->assertCount(0, app(Combinados::class)->vencidas($this->gerente));
        $this->assertCount(0, app(Pendencias::class)->listar($this->gerente)->where('tipo', 'pagamento_vencido'));
        $this->assertSame('paga', app(Combinados::class)->situacao($this->ordem)['parcelas'][0]->situacao);
    }

    public function test_baixa_por_ordem_de_vencimento_e_parcial(): void
    {
        app(Combinados::class)->registrar($this->ordem, $this->gerente, [
            ['valor_centavos' => 40000, 'vencimento' => $this->hoje(20)],
            ['valor_centavos' => 60000, 'vencimento' => $this->hoje(5)],
        ]);
        app(Financeiro::class)->registrarManual($this->ordem, $this->recepcao, 'cartao_externo', 70000);
        $p = app(Combinados::class)->situacao($this->ordem)['parcelas'];
        $this->assertSame([60000, 10000], $p->pluck('pago_centavos')->all()); // a de vencimento mais próximo é abatida primeiro
        $this->assertSame(['paga', 'parcial'], $p->pluck('situacao')->all());
        // Pagamento em revisão não abate.
        $this->assertSame(0, Pagamento::where('status', 'em_revisao')->count());
    }

    public function test_soma_diferente_do_saldo_e_vencimento_passado_sao_recusados(): void
    {
        $c = app(Combinados::class);
        foreach ([
            [['valor_centavos' => 50000, 'vencimento' => $this->hoje(10)]],
            [['valor_centavos' => 60000, 'vencimento' => $this->hoje()], ['valor_centavos' => 50000, 'vencimento' => $this->hoje(10)]],
            [['valor_centavos' => 100000, 'vencimento' => $this->hoje(-1)]],
            [['valor_centavos' => 100000, 'vencimento' => 'amanhã']],
            [],
        ] as $parcelas) {
            try {
                $c->registrar($this->ordem, $this->gerente, $parcelas);
                $this->fail('combinado inválido deveria ser recusado');
            } catch (ValidationException) {
            }
        }
        $this->entrarComo($this->gerente)->post(route('combinados.registrar', $this->ordem), ['parcelas' => [['valor' => '500,00', 'vencimento' => $this->hoje(10)]]])
            ->assertSessionHasErrors('parcelas');
        $this->assertSame(0, CombinadoPagamento::count());
    }

    public function test_constraints_no_banco(): void
    {
        $c = app(Combinados::class)->registrar($this->ordem, $this->gerente, [['valor_centavos' => 100000, 'vencimento' => $this->hoje(3)]]);
        $this->expectException(QueryException::class);
        DB::table('parcelas_previstas')->insert(['combinado_id' => $c->id, 'ordem_id' => $this->ordem->id, 'unidade_id' => $this->unidade->id, 'sequencia' => 2, 'valor_centavos' => 0, 'vencimento' => $this->hoje(3)]);
    }

    public function test_um_vigente_por_ordem_no_banco(): void
    {
        $c = app(Combinados::class)->registrar($this->ordem, $this->gerente, [['valor_centavos' => 100000, 'vencimento' => $this->hoje(3)]]);
        $this->expectException(QueryException::class);
        DB::table('combinados_pagamento')->insert(['ordem_id' => $this->ordem->id, 'unidade_id' => $this->unidade->id, 'orcamento_id' => $c->orcamento_id,
            'saldo_centavos' => 100000, 'status' => 'vigente', 'criado_por' => $this->gerente->id]);
    }

    public function test_ajuste_e_cancelamento_exigem_motivo_e_sao_auditados(): void
    {
        $c = app(Combinados::class);
        $c->registrar($this->ordem, $this->gerente, [['valor_centavos' => 100000, 'vencimento' => $this->hoje(10)]]);
        try {
            $c->registrar($this->ordem, $this->gerente, [['valor_centavos' => 50000, 'vencimento' => $this->hoje(5)], ['valor_centavos' => 50000, 'vencimento' => $this->hoje(15)]]);
            $this->fail('ajuste sem motivo deveria ser recusado');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('motivo_combinado', $e->errors());
        }
        $novo = $c->registrar($this->ordem, $this->gerente, [['valor_centavos' => 50000, 'vencimento' => $this->hoje(5)], ['valor_centavos' => 50000, 'vencimento' => $this->hoje(15)]], 'cliente pediu dividir em duas');
        $this->assertSame(['substituido', 'vigente'], CombinadoPagamento::orderBy('id')->pluck('status')->all());
        $this->assertSame('cliente pediu dividir em duas', AuditEvent::where('acao', 'combinado_ajustado')->value('motivo'));
        $this->assertCount(2, $novo->parcelas);

        $this->entrarComo($this->gerente)->post(route('combinados.cancelar', $this->ordem), ['motivo_combinado' => ''])->assertSessionHasErrors('motivo_combinado');
        $this->entrarComo($this->gerente)->post(route('combinados.cancelar', $this->ordem), ['motivo_combinado' => 'cliente vai quitar na retirada'])->assertSessionHasNoErrors();
        $this->assertSame('cancelado', $novo->fresh()->status);
        $this->assertNotNull($novo->fresh()->encerrado_por);
        $this->assertTrue(AuditEvent::where('acao', 'combinado_cancelado')->where('motivo', 'cliente vai quitar na retirada')->exists());
        $this->assertNull($c->vigente($this->ordem));
    }

    public function test_permissao_e_unidade_no_servidor(): void
    {
        // Recepção não tem a permissão por padrão: 403 na rota e no serviço.
        $this->entrarComo($this->recepcao)->post(route('combinados.registrar', $this->ordem), ['parcelas' => [['valor' => '1000,00', 'vencimento' => $this->hoje(10)]]])->assertForbidden();
        try {
            $this->combinar500em10dias($this->recepcao);
            $this->fail('recepção sem concessão não combina');
        } catch (AuthorizationException) {
        }
        // Concessão individual pelo proprietário habilita a recepção.
        $this->recepcao->forceFill(['permissoes_extras' => ['combinar_pagamento']])->save();
        $this->entrarComo($this->recepcao->fresh())->post(route('combinados.registrar', $this->ordem), ['parcelas' => [['valor' => '1000,00', 'vencimento' => $this->hoje(10)]]])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, CombinadoPagamento::count());

        // Gerente de outra unidade: 403.
        $outra = Unidade::factory()->create(['codigo' => 'GYN']);
        $gerenteOutra = $this->usuario(Papel::Gerente, $outra);
        $this->entrarComo($gerenteOutra)->post(route('combinados.cancelar', $this->ordem), ['motivo_combinado' => 'tentativa indevida'])->assertForbidden();
        $this->assertCount(0, app(Combinados::class)->vencidas($gerenteOutra->fresh()));
        $this->assertSame('vigente', CombinadoPagamento::first()->status);

        // Escopo das vencidas por unidade: só a unidade do usuário; proprietário vê todas.
        $this->travel(11)->days();
        $this->assertCount(1, app(Combinados::class)->vencidas($this->gerente));
        $this->assertCount(1, app(Combinados::class)->vencidas($this->dono));
        $this->assertCount(0, app(Combinados::class)->vencidas($gerenteOutra));
    }

    public function test_entrega_com_combinado_sem_excecao_e_sem_combinado_regra_atual(): void
    {
        $f = app(Financeiro::class);
        $this->pagarPix($this->ordem, 50000);
        $this->prontaParaEntrega($this->ordem);

        // Sem combinado: recepção não libera; gerente só com motivo e gera exceção (regra atual).
        try {
            $f->entregar($this->ordem, $this->recepcao);
            $this->fail('sem combinado a recepção não libera com saldo');
        } catch (AuthorizationException) {
        }
        // Com combinado vigente cobrindo o saldo: recepção (só "entregar") entrega sem exceção.
        $comb = $this->combinar500em10dias();
        $this->entrarComo($this->recepcao)->get(route('ordens.show', [$this->ordem, 'aba' => 'pagamentos']))->assertSee('Registrar entrega com pagamento combinado');
        $this->entrarComo($this->recepcao)->post(route('ordens.entregar', $this->ordem))->assertSessionHasNoErrors();
        $e = Entrega::where('ordem_id', $this->ordem->id)->firstOrFail();
        $this->assertFalse($e->excecao);
        $this->assertSame($comb->id, $e->combinado_id);
        $this->assertSame(50000, (int) $e->saldo_centavos);
        $this->assertSame(StatusOrdem::Entregue, $this->ordem->fresh()->status);
        $this->assertTrue(AuditEvent::where('acao', 'entrega_com_combinado')->exists());
        $this->assertCount(0, app(Pendencias::class)->listar($this->gerente)->where('tipo', 'entrega_excecao'));

        // Entregue com saldo combinado: continua a receber, e pagamento após a entrega abate a parcela.
        $f->registrarManual($this->ordem->fresh(), $this->recepcao, 'dinheiro', 50000);
        $this->assertSame('paga', app(Combinados::class)->situacao($this->ordem)['parcelas'][0]->situacao);
    }

    public function test_entrega_sem_combinado_mantem_excecao(): void
    {
        $f = app(Financeiro::class);
        $this->pagarPix($this->ordem, 50000);
        $this->prontaParaEntrega($this->ordem);
        try {
            $f->entregar($this->ordem, $this->gerente);
            $this->fail('sem motivo deve recusar');
        } catch (ValidationException) {
        }
        $e = $f->entregar($this->ordem, $this->gerente, 'cliente conhecido, paga semana que vem');
        $this->assertTrue($e->excecao);
        $this->assertNull($e->combinado_id);
        $this->assertCount(1, app(Pendencias::class)->listar($this->gerente)->where('tipo', 'entrega_excecao'));
    }

    public function test_combinado_desatualizado_nao_cobre_entrega(): void
    {
        $this->prontaParaEntrega($this->ordem);
        app(Financeiro::class)->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 50000);
        $pagamentoAnterior = Pagamento::firstOrFail();
        $this->combinar500em10dias();
        // Devolução de recebimento feito ANTES do combinado: saldo passa a exceder o combinado.
        app(Financeiro::class)->devolver($pagamentoAnterior, $this->gerente, 10000, 'cobrado a mais na entrada');
        $s = app(Combinados::class)->situacao($this->ordem);
        $this->assertTrue($s['desatualizado']);
        $this->assertFalse($s['cobre']);
        $this->expectException(AuthorizationException::class);
        app(Financeiro::class)->entregar($this->ordem, $this->recepcao);
    }

    public function test_devolucao_de_recebimento_do_combinado_reabre_parcela(): void
    {
        $f = app(Financeiro::class);
        app(Combinados::class)->registrar($this->ordem, $this->gerente, [
            ['valor_centavos' => 50000, 'vencimento' => $this->hoje()],
            ['valor_centavos' => 50000, 'vencimento' => $this->hoje(10)],
        ]);
        $f->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 50000);
        $this->pagarPix($this->ordem, 50000);
        $this->assertSame(['paga', 'paga'], app(Combinados::class)->situacao($this->ordem)['parcelas']->pluck('situacao')->all());

        // Devolução Pix concluída de R$ 200 reabre a parcela mais distante (a última abatida).
        $pix = Pagamento::where('meio', 'pix')->firstOrFail();
        $d = $f->devolver($pix, $this->gerente, 20000, 'desconto concedido após o serviço');
        $this->assertSame('concluida', $d->fresh()->status);
        $p = app(Combinados::class)->situacao($this->ordem)['parcelas'];
        $this->assertSame(['paga', 'parcial'], $p->pluck('situacao')->all());
        $this->assertSame(30000, (int) $p[1]->pago_centavos);
        $this->assertSame(20000, $f->resumo($this->ordem)['saldo']);
        // Caixa não muda: o Pix recebido continua no dia; a devolução não é recebimento.
        $this->assertSame(100000, (int) $f->recebimentosDoDia($this->unidade->id, $this->hoje())->sum('valor_centavos'));

        // Vencida após o prazo da parcela reaberta.
        $this->travel(11)->days();
        $this->assertSame(20000, app(Combinados::class)->vencidas($this->gerente)->first()->emAbertoCentavos());
    }

    public function test_mecanico_nao_ve_nem_combina(): void
    {
        $o = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'João', 'telefone' => '61991234500', 'placa' => 'XYZ9A87', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->mecanico);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $this->dono, [['descricao' => 'Rodas', 'quantidade' => '1', 'preco' => '1000']]);
        $s->enviar($v, $this->dono);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
        $o = $o->fresh();
        app(Combinados::class)->registrar($o, $this->gerente, [['valor_centavos' => 100000, 'vencimento' => $this->hoje(1)]]);

        // Mesmo com concessão individual o mecânico não combina nem vê.
        $this->mecanico->forceFill(['permissoes_extras' => ['combinar_pagamento']])->save();
        $mec = $this->mecanico->fresh();
        $this->assertFalse(Combinados::podeVer($mec));
        $this->entrarComo($mec)->post(route('combinados.cancelar', $o), ['motivo_combinado' => 'tentativa indevida'])->assertForbidden();
        $this->entrarComo($mec)->get(route('caixa.index', ['aba' => 'receber']))->assertForbidden();
        $this->entrarComo($mec)->get(route('ordens.show', [$o, 'aba' => 'pagamentos']))->assertOk()
            ->assertDontSee('Pagamento combinado')->assertDontSee('Combinar pagamento')->assertDontSee('Próxima parcela');
        $this->travel(3)->days();
        $this->assertCount(0, app(Combinados::class)->vencidas($mec));
        $this->assertSame('vigente', CombinadoPagamento::where('ordem_id', $o->id)->value('status'));
    }

    public function test_sino_avisa_recepcao_de_parcela_vencida_e_entrega_atrasada_e_mecanico_nao_ve_valores(): void
    {
        $this->pagarPix($this->ordem, 50000);
        $this->combinar500em10dias();
        app(\App\Services\PrazosEntrega::class)->definir($this->ordem->fresh(), $this->gerente, 2);
        $this->travel(15)->days();

        $html = $this->entrarComo($this->recepcao)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Pagamento combinado vencido', $html);
        $this->assertStringContainsString($this->ordem->numero.' · R$ 500,00 em aberto', $html);
        $this->assertStringContainsString('Prazo de entrega vencido', $html);
        $gerente = $this->entrarComo($this->gerente)->get('/')->getContent();
        $this->assertSame(1, substr_count($gerente, 'Pagamento combinado vencido</strong>'), 'gestor recebe pela lista de pendências, sem duplicar');
        $mec = $this->entrarComo($this->mecanico)->get('/fila')->assertOk()->getContent();
        $this->assertStringNotContainsString('Pagamento combinado vencido', $mec);
        $this->assertStringNotContainsString('R$ 500,00', $mec);
    }
}
