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

class AvaliacaoFinanceiraTest extends TestCase
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


    public function test_entrega_excepcional_permite_quitar_saldo_depois(): void
    {
        $f = app(Financeiro::class);
        $this->prontaParaEntrega($this->ordem);
        $f->entregar($this->ordem, $this->gerente, 'Pagamento posterior combinado');
        $f->registrarManual($this->ordem->fresh(), $this->recepcao, 'dinheiro', 48000);
        $this->assertSame(0, $f->resumo($this->ordem)['saldo']);
    }

    public function test_fechamento_considera_saida_em_dinheiro_por_devolucao(): void
    {
        $f = app(Financeiro::class);
        $p = $f->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 48000);
        $f->devolver($p, $this->gerente, 5000, 'Devolvido em dinheiro ao cliente');
        $caixa = $f->fecharCaixa($this->unidade->id, now('America/Sao_Paulo')->format('Y-m-d'), $this->gerente, 43000, null);
        $this->assertSame(43000, $caixa->dinheiro_sistema_centavos, 'Valor esperado em caixa deve descontar os 50 reais devolvidos');
    }

    public function test_devolucao_parcial_em_revisao_nao_inventa_divida(): void
    {
        $f = app(Financeiro::class);
        $c = $f->gerarCobranca($this->ordem, $this->recepcao);
        app(SimuladorPix::class)->simularPagamento($c->txid, 50000);
        $this->assertSame('em_revisao', $f->conciliarCobranca($c->txid));
        $p = Pagamento::sole();
        $f->devolver($p, $this->gerente, 2000, 'Devolucao do valor excedente');
        $this->assertSame(48000, $f->resumo($this->ordem)['saldo'], 'Enquanto pagamento ainda nao conta, devolucao dele nao deve aumentar divida aprovada');
    }
}
