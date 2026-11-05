<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Integracoes\Pix\SimuladorPix;
use App\Models\Pagamento;
use App\Models\Recebedor;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Notificações/conciliações simultâneas do mesmo pagamento (processos separados): um único crédito. */
class PagamentoConcorrenteTest extends TestCase
{
    public function beginDatabaseTransaction(): void
    {
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh');
        parent::tearDown();
    }

    public function test_processamento_simultaneo_nao_duplica_credito(): void
    {
        config(['oficina.integracoes' => 'simulado']);
        $u = Unidade::factory()->create();
        $rec = User::factory()->papel(Papel::Recepcao, $u)->create();
        $mec = User::factory()->papel(Papel::Mecanico, $u)->create();
        $ger = User::factory()->papel(Papel::Gerente, $u)->create();
        Recebedor::create(['unidade_id' => $u->id, 'provedor' => 'simulado', 'identificador_conta' => 'conta', 'descricao' => 'c', 'ativo' => true, 'criado_por' => $rec->id]);
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'Ana', 'telefone' => '61991234567', 'placa' => 'ABC1234', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $mec);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $ger, [['descricao' => 'S', 'quantidade' => '1', 'preco' => '100']]);
        $s->enviar($v, $ger);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
        $c = app(Financeiro::class)->gerarCobranca($o, $rec);
        app(SimuladorPix::class)->simularPagamento($c->txid);
        DB::disconnect();

        $pids = [];
        for ($i = 0; $i < 6; $i++) {
            if (($pid = pcntl_fork()) === 0) {
                DB::reconnect();
                try {
                    app(Financeiro::class)->conciliarCobranca($c->txid);
                } catch (\Throwable) {
                }
                DB::disconnect();
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $st);
        }
        DB::reconnect();
        $this->assertSame(1, Pagamento::where('ordem_id', $o->id)->count());
        $this->assertSame(0, app(Financeiro::class)->resumo($o)['saldo']);
    }
}
