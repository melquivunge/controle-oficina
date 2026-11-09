<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Services\UnificacaoClientes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Unifica cadastros com o MESMO NOME (sem acento/maiúsculas/espaços) só quando é seguro (09/11/2026):
 * A = mesmo CPF/CNPJ; B = nenhum CPF/CNPJ ou telefone diferente entre eles. Conflito (C) nunca é unificado aqui:
 * fica listado para decisão na ficha. Principal = quem tem mais histórico (OS novas + antigas), depois CPF/CNPJ, depois o mais antigo.
 * Sem --executar só simula. Usa UnificacaoClientes (nada se perde).
 */
class UnificarDuplicados extends Command
{
    protected $signature = 'oficina:unificar-duplicados {--executar : grava de verdade}';

    protected $description = 'Unifica cadastros duplicados seguros (mesmo nome, sem conflito de CPF/CNPJ ou telefone). Sem --executar só simula.';

    public function handle(UnificacaoClientes $unificacao): int
    {
        $grupos = DB::select("select array_agg(id order by id) ids from clientes where mesclado_em_id is null
            group by oficina_chave_nome(regexp_replace(trim(nome), '\\s+', ' ', 'g')) having count(*) > 1");
        $r = ['A' => 0, 'B' => 0, 'C' => 0, 'unificados' => 0];
        $conflitos = [];
        $executar = (bool) $this->option('executar');
        DB::beginTransaction();
        try {
            foreach ($grupos as $g) {
                $cs = Cliente::whereIn('id', array_map('intval', explode(',', trim($g->ids, '{}'))))->get();
                $docs = $cs->pluck('documento')->filter()->unique();
                $tels = $cs->pluck('telefone')->filter()->unique();
                $tipo = match (true) {
                    $docs->count() === 1 && $cs->whereNotNull('documento')->count() >= 2 => 'A',
                    $docs->count() <= 1 && $tels->count() <= 1 => 'B',
                    default => 'C',
                };
                $r[$tipo]++;
                if ($tipo === 'C') {
                    $conflitos[] = $cs->pluck('id')->join(',');

                    continue;
                }
                $peso = fn (Cliente $c) => [-(DB::table('ordens')->where('cliente_id', $c->id)->count() + DB::table('legado_registros')->where('tabela', 'TBL_OS')->where('cliente_id', $c->id)->count()),
                    $c->documento ? 0 : 1, $c->id];
                $ordenados = $cs->sortBy($peso)->values();
                foreach ($ordenados->slice(1) as $dup) {
                    $unificacao->unificar($ordenados[0], $dup, null, "Unificação automática de duplicados (tipo {$tipo}: ".($tipo === 'A' ? 'mesmo CPF/CNPJ' : 'mesmo nome, sem conflito').')');
                    $r['unificados']++;
                }
            }
            $executar ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $this->line(($executar ? 'UNIFICADO' : 'SIMULAÇÃO (nada gravado; use --executar)').':');
        $this->table(['Item', 'Quantidade'], [['Grupos A (mesmo CPF/CNPJ)', $r['A']], ['Grupos B (mesmo nome, sem conflito)', $r['B']],
            ['Grupos C (conflito: NÃO unificados, decidir na ficha)', $r['C']], ['Cadastros unificados', $r['unificados']]]);
        if ($conflitos) {
            $this->line('Grupos C (ids): '.implode(' | ', $conflitos));
        }

        return self::SUCCESS;
    }
}
