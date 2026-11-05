<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Aprovacao;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Decisões simultâneas sobre a mesma versão (processos separados): só uma prevalece. */
class AprovacaoConcorrenteTest extends TestCase
{
    public function beginDatabaseTransaction(): void
    {
        // Dados commitados para os processos filhos.
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh'); // limpa dados commitados (auditoria é append-only)
        parent::tearDown();
    }

    public function test_aprovacao_e_recusa_simultaneas_geram_uma_unica_decisao(): void
    {
        $unidade = Unidade::factory()->create();
        $rec = User::factory()->papel(Papel::Recepcao, $unidade)->create();
        $mec = User::factory()->papel(Papel::Mecanico, $unidade)->create();
        $ger = User::factory()->papel(Papel::Gerente, $unidade)->create();
        $ordem = app(Entradas::class)->registrar($rec, ['unidade_id' => $unidade->id, 'nome' => 'Ana', 'telefone' => '61991234567', 'placa' => 'ABC1234', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($ordem, $mec);
        $s = app(Orcamentos::class);
        $v1 = $s->salvarRascunho($ordem, $ger, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '100']]);
        $s->enviar($v1, $ger);
        DB::disconnect();

        $pids = [];
        foreach (array_merge(array_fill(0, 4, 'aprovado'), array_fill(0, 4, 'recusado')) as $n => $decisao) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::reconnect();
                try {
                    app(Orcamentos::class)->decidir($v1->id, $ordem->id, $decisao, 'link_cliente');
                    $r = 'ok';
                } catch (\Throwable $e) {
                    $r = 'rejeitado';
                }
                file_put_contents(sys_get_temp_dir()."/dec-{$v1->id}-{$n}", $r);
                DB::disconnect();
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $st);
        }
        DB::reconnect();

        $resultados = array_map(fn ($n) => file_get_contents(sys_get_temp_dir()."/dec-{$v1->id}-{$n}"), range(0, 7));
        $this->assertSame(1, count(array_keys($resultados, 'ok')), implode(',', $resultados));
        $this->assertSame(1, Aprovacao::where('orcamento_id', $v1->id)->count());
        $decisao = Aprovacao::where('orcamento_id', $v1->id)->value('decisao');
        $this->assertSame($decisao, $v1->fresh()->status);
    }
}
