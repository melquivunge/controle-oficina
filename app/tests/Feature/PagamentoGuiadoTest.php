<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\AuditEvent;
use App\Models\Cobranca;
use App\Models\CombinadoPagamento;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\Recebedor;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Combinados;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use App\Support\Fuso;
use Tests\TestCase;

/** Aba "Pagamentos e entrega" guiada: "Parte agora, parte depois" num envio (decisão 7 de 30/10/2026). */
class PagamentoGuiadoTest extends TestCase
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
        $this->travelTo(now()->setTimezone('America/Sao_Paulo')->setTime(10, 0)->utc());
        $this->unidade = Unidade::factory()->create(['codigo' => 'ASN']);
        $this->dono = User::factory()->proprietario()->create();
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
        Recebedor::create(['unidade_id' => $this->unidade->id, 'provedor' => 'simulado', 'identificador_conta' => 'conta-oficial-asn', 'descricao' => 'Conta PJ', 'ativo' => true, 'criado_por' => $this->dono->id]);
        $o = app(Entradas::class)->registrar($this->dono, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana', 'telefone' => '61991234567', 'placa' => 'ABC1D23', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->dono);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $this->dono, [['descricao' => 'Jogo de rodas', 'quantidade' => '1', 'preco' => '210']]);
        $s->enviar($v, $this->dono);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
        $this->ordem = $o->fresh();
    }

    private function dia(int $dias = 0): string
    {
        return Fuso::exibir(now()->addDays($dias), 'Y-m-d');
    }

    private function url(): string
    {
        return "/ordens/{$this->ordem->numero}?aba=pagamentos";
    }

    private function dividir(User $quem, string $forma, array $futuras, string $agora = '105,00')
    {
        return $this->entrarComo($quem)->from($this->url())->post(route('pagamentos.dividido', $this->ordem),
            ['modo' => 'parte', 'valor_agora' => $agora, 'forma_agora' => $forma, 'futuras' => $futuras]);
    }

    public function test_parte_agora_em_dinheiro_e_restante_em_10_dias_num_envio(): void
    {
        // Só a data: o restante (R$ 105,00) é calculado.
        $this->dividir($this->gerente, 'dinheiro', [['vencimento' => $this->dia(10), 'valor' => '', 'meio' => 'dinheiro']])
            ->assertSessionHasNoErrors()->assertRedirect($this->url());

        $p = Pagamento::sole();
        $this->assertSame([10500, 'dinheiro', 'registrado'], [$p->valor_centavos, $p->meio, $p->status]);
        $sit = app(Combinados::class)->situacao($this->ordem);
        $this->assertSame('vigente', $sit['combinado']->status);
        $this->assertSame(21000, (int) $sit['combinado']->saldo_centavos);
        $this->assertSame([[10500, $this->dia(), 'paga'], [10500, $this->dia(10), 'aberta']],
            $sit['parcelas']->map(fn ($x) => [(int) $x->valor_centavos, $x->vencimento->format('Y-m-d'), $x->situacao])->all());
        $this->assertTrue($sit['cobre']);
        // Caixa do dia só conta a metade que entrou.
        $this->assertSame(10500, (int) app(Financeiro::class)->recebimentosDoDia($this->unidade->id, $this->dia())->sum('valor_centavos'));
        $this->assertSame(10500, app(Financeiro::class)->resumo($this->ordem)['saldo']);
        // Auditoria pelos serviços existentes.
        $this->assertSame(1, AuditEvent::where('acao', 'combinado_registrado')->count());
        $this->assertSame(1, AuditEvent::where('acao', 'recebimento_manual')->count());

        $this->get($this->url())->assertOk()->assertSee('Parcelas combinadas')->assertSee('Receber pagamento');
    }

    public function test_parte_agora_no_pix_gera_cobranca_pendente_da_primeira_parcela(): void
    {
        $this->dividir($this->gerente, 'pix', [['vencimento' => $this->dia(10), 'valor' => '105,00', 'meio' => 'dinheiro']])->assertSessionHasNoErrors();

        $c = Cobranca::sole();
        $this->assertSame([10500, 'pendente'], [$c->valor_centavos, $c->status]);
        $this->assertSame(0, Pagamento::count());
        $sit = app(Combinados::class)->situacao($this->ordem);
        $this->assertSame(['aberta', 'aberta'], $sit['parcelas']->pluck('situacao')->all()); // só quita com confirmação do provedor
        $this->assertSame(21000, app(Financeiro::class)->resumo($this->ordem)['saldo']);
    }

    public function test_soma_invalida_e_recusada_sem_gravar_nada(): void
    {
        $this->dividir($this->gerente, 'dinheiro', [['vencimento' => $this->dia(10), 'valor' => '50,00']])->assertSessionHasErrors('futuras');
        $this->dividir($this->gerente, 'dinheiro', [['vencimento' => $this->dia(10), 'valor' => '']], '210,00')->assertSessionHasErrors('futuras');
        $this->dividir($this->gerente, 'dinheiro', [['vencimento' => $this->dia(-1), 'valor' => '']])->assertSessionHasErrors('futuras');
        $this->dividir($this->gerente, 'dinheiro', [['vencimento' => '', 'valor' => '']])->assertSessionHasErrors('futuras');
        $this->dividir($this->gerente, 'dinheiro', [['vencimento' => $this->dia(10)]], 'abc')->assertSessionHasErrors('valor_agora');
        $this->assertSame([0, 0, 0], [CombinadoPagamento::count(), Pagamento::count(), Cobranca::count()]);
    }

    public function test_tres_datas_futuras(): void
    {
        $this->dividir($this->gerente, 'cartao_externo', [
            ['vencimento' => $this->dia(10), 'valor' => '35,00'], ['vencimento' => $this->dia(20), 'valor' => '35,00', 'meio' => 'pix'], ['vencimento' => $this->dia(30), 'valor' => '35,00'],
        ])->assertSessionHasNoErrors();
        $this->assertCount(4, CombinadoPagamento::sole()->parcelas);
        $this->assertSame('cartao_externo', Pagamento::sole()->meio);
    }

    public function test_recepcao_403_no_endpoint_e_ve_aviso(): void
    {
        $this->dividir($this->recepcao, 'dinheiro', [['vencimento' => $this->dia(10)]])->assertForbidden();
        $this->assertSame([0, 0], [CombinadoPagamento::count(), Pagamento::count()]);

        $html = $this->get($this->url())->assertOk()->getContent();
        $this->assertStringContainsString('Para combinar o restante com data, peça ao gerente', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringNotContainsString(route('pagamentos.dividido', $this->ordem), $html);
    }

    public function test_tudo_agora_parcial_continua_funcionando_para_recepcao(): void
    {
        $this->entrarComo($this->recepcao)->from($this->url())->post(route('pagamentos.agora', $this->ordem), ['valor_agora' => '100,00', 'forma_agora' => 'dinheiro'])
            ->assertSessionHasNoErrors();
        $this->assertSame(11000, app(Financeiro::class)->resumo($this->ordem)['saldo']);
        $this->post(route('pagamentos.agora', $this->ordem), ['valor_agora' => '110,00', 'forma_agora' => 'pix'])->assertSessionHasNoErrors();
        $this->assertSame([11000, 'pendente'], [Cobranca::sole()->valor_centavos, Cobranca::sole()->status]);
        // Acima do saldo: erro junto do campo de valor.
        $this->post(route('pagamentos.agora', $this->ordem), ['valor_agora' => '999,00', 'forma_agora' => 'dinheiro'])->assertSessionHasErrors('valor_agora');
        // Endpoints antigos seguem válidos.
        $this->post(route('pagamentos.manual', $this->ordem), ['meio' => 'dinheiro', 'valor_recebido' => '10,00'])->assertSessionHasNoErrors();
    }

    public function test_mecanico_nada(): void
    {
        $this->dividir($this->mecanico, 'dinheiro', [['vencimento' => $this->dia(10)]])->assertForbidden();
        $this->post(route('pagamentos.agora', $this->ordem), ['valor_agora' => '10,00', 'forma_agora' => 'dinheiro'])->assertForbidden();
        $this->assertSame([0, 0, 0], [CombinadoPagamento::count(), Pagamento::count(), Cobranca::count()]);
        $this->get($this->url())->assertDontSee('Como o cliente vai pagar')->assertDontSee('R$ 210,00');
    }

    public function test_gerente_de_outra_unidade_403(): void
    {
        $outro = $this->usuario(Papel::Gerente, Unidade::factory()->create(['codigo' => 'GYN']));
        $this->dividir($outro, 'dinheiro', [['vencimento' => $this->dia(10)]])->assertForbidden();
        $this->assertSame(0, CombinadoPagamento::count());
    }

    public function test_gerente_ve_tres_opcoes(): void
    {
        $this->entrarComo($this->gerente)->get($this->url())->assertOk()
            ->assertSee('Como o cliente vai pagar?')->assertSee('Tudo agora')->assertSee('Parte agora, parte depois')->assertSee('Tudo depois')
            ->assertSee(route('pagamentos.dividido', $this->ordem));
    }
}
