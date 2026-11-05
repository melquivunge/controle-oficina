<?php

namespace Tests\Feature;

use App\Services\Entradas;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concorrência real: processos separados (pcntl_fork), cada um com sua conexão, pedindo números
 * para a mesma unidade. Dados precisam estar commitados, por isso não usa a transação do RefreshDatabase.
 */
class NumeracaoConcorrenteTest extends TestCase
{
    public function beginDatabaseTransaction(): void
    {
        // Sem transação envolvente: os processos filhos precisam enxergar a unidade.
    }

    public function test_numeros_sao_unicos_e_sem_lacunas_sob_concorrencia(): void
    {
        $id = DB::table('unidades')->insertGetId(['codigo' => 'CC'.random_int(100, 999), 'nome' => 'Concorrência', 'ativa' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::disconnect();

        $filhos = [];
        for ($p = 0; $p < 6; $p++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::reconnect();
                $numeros = [];
                for ($i = 0; $i < 25; $i++) {
                    $numeros[] = app(Entradas::class)->proximoNumero($id);
                }
                file_put_contents(sys_get_temp_dir()."/seq-{$id}-{$p}", implode(',', $numeros));
                DB::disconnect();
                exit(0);
            }
            $filhos[] = $pid;
        }
        foreach ($filhos as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $todos = [];
        for ($p = 0; $p < 6; $p++) {
            $todos = array_merge($todos, array_map('intval', explode(',', file_get_contents(sys_get_temp_dir()."/seq-{$id}-{$p}"))));
        }
        sort($todos);
        $this->assertSame(range(1, 150), $todos);
        $this->assertSame(150, (int) DB::table('unidade_sequencias')->where('unidade_id', $id)->value('ultimo_numero'));

        DB::table('unidade_sequencias')->where('unidade_id', $id)->delete();
        DB::table('unidades')->where('id', $id)->delete();
    }
}
