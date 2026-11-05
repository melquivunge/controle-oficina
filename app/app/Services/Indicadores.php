<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Unidade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores por unidade e período (instantes UTC; período em dias locais).
 * Valores financeiros só de transações efetivas; orçado, aprovado, recebido e pendente separados.
 */
class Indicadores
{
    /**
     * @param  ?int  $responsavel  usuário que registrou a entrada (ordens.criado_por): restringe as métricas às ordens dele
     * @return array<int, array<string, int>> chave = unidade_id
     */
    public function porUnidade(array $unidadeIds, CarbonImmutable $inicio, CarbonImmutable $fim, ?int $responsavel = null): array
    {
        // Filtro por quem registrou a entrada. Inteiro validado e convertido: seguro para interpolar.
        $ord = fn (string $col = 'ordem_id') => $responsavel ? " and {$col} in (select id from ordens where criado_por = ".(int) $responsavel.')' : '';
        $criador = $responsavel ? ' and criado_por = '.(int) $responsavel : '';
        $p = [$inicio, $fim];
        $agr = fn (string $sql, array $bind = []) => collect(DB::select($sql, $bind))->keyBy('unidade_id');
        $in = implode(',', array_map('intval', $unidadeIds ?: [0]));

        $entradas = $agr("select unidade_id, count(*) n from ordens where unidade_id in ($in)$criador and entrada_em >= ? and entrada_em < ? group by unidade_id", $p);
        $saidas = $agr("select unidade_id, count(*) n, count(*) filter (where excecao) excecoes, coalesce(sum(saldo_centavos) filter (where excecao),0) liberado from entregas where unidade_id in ($in){$ord()} and entregue_em >= ? and entregue_em < ? group by unidade_id", $p);
        $orcado = $agr("select unidade_id, coalesce(sum(total_centavos),0) v from orcamentos where unidade_id in ($in){$ord()} and enviado_em >= ? and enviado_em < ? group by unidade_id", $p);
        $aprovado = $agr("select unidade_id, coalesce(sum(total_centavos),0) v, count(*) n from aprovacoes where unidade_id in ($in){$ord()} and decisao = 'aprovado' and decidido_em >= ? and decidido_em < ? group by unidade_id", $p);
        $recusas = $agr("select unidade_id, count(*) n from aprovacoes where unidade_id in ($in){$ord()} and decisao = 'recusado' and decidido_em >= ? and decidido_em < ? group by unidade_id", $p);
        $descontos = $agr("select o.unidade_id, coalesce(sum(o.desconto_centavos),0) v, count(*) n from orcamentos o join aprovacoes a on a.orcamento_id = o.id
            where o.unidade_id in ($in){$ord('o.ordem_id')} and a.decisao = 'aprovado' and o.desconto_centavos > 0 and a.decidido_em >= ? and a.decidido_em < ? group by o.unidade_id", $p);
        $recebido = $agr("select unidade_id, coalesce(sum(valor_centavos),0) v,
                coalesce(sum(valor_centavos) filter (where meio = 'pix'),0) pix, coalesce(sum(valor_centavos) filter (where meio <> 'pix'),0) manual
            from pagamentos where unidade_id in ($in){$ord()} and status in ('confirmado','registrado') and recebido_em >= ? and recebido_em < ? group by unidade_id", $p);
        // Só devoluções concluídas (Pix processando/recusado não saiu da conta), na data da conclusão.
        $devolvido = $agr("select unidade_id, coalesce(sum(valor_centavos),0) v from devolucoes where unidade_id in ($in){$ord()} and status = 'concluida' and concluida_em >= ? and concluida_em < ? group by unidade_id", $p);
        $cancelamentos = AuditEvent::whereIn('unidade_id', $unidadeIds)->where('acao', 'ordem_status')->where('depois->status', 'cancelado')
            ->when($responsavel, fn ($q) => $q->where('entidade_tipo', 'ordem')->whereIn('entidade_id', DB::table('ordens')->select('id')->where('criado_por', $responsavel)))
            ->whereBetween('ocorrido_em', $p)->selectRaw('unidade_id, count(*) n')->groupBy('unidade_id')->pluck('n', 'unidade_id');
        $falhas = $agr("select unidade_id, sum(n) n from (
                select unidade_id, count(*) n from cobrancas where status = 'falha'{$ord()} and updated_at >= ? and updated_at < ? group by unidade_id
                union all select unidade_id, count(*) from message_deliveries where status = 'falhou'{$ord()} and updated_at >= ? and updated_at < ? group by unidade_id
                union all select unidade_id, count(*) from fiscal_documents where (status = 'rejeitado' or (status = 'pendente' and mensagem is not null)){$ord()} and updated_at >= ? and updated_at < ? group by unidade_id
                union all select unidade_id, count(*) from pagamentos where status = 'em_revisao'{$ord()} and recebido_em >= ? and recebido_em < ? group by unidade_id
                union all select unidade_id, count(*) from devolucoes where status = 'falhou'{$ord()} and updated_at >= ? and updated_at < ? group by unidade_id
            ) f where unidade_id in ($in) group by unidade_id", [...$p, ...$p, ...$p, ...$p, ...$p]);

        // Estado atual (não depende do período).
        $porEtapa = collect(DB::select("select unidade_id, status, count(*) n from ordens where unidade_id in ($in)$criador and status not in ('entregue','cancelado') group by unidade_id, status"))->groupBy('unidade_id');
        $pendente = $agr("select o.unidade_id, coalesce(sum(greatest(0, a.total_centavos - coalesce(r.v,0) + coalesce(d.v,0))),0) v
            from ordens o join orcamentos a on a.ordem_id = o.id and a.status = 'aprovado'
            left join (select ordem_id, sum(valor_centavos) v from pagamentos where status in ('confirmado','registrado') group by ordem_id) r on r.ordem_id = o.id
            left join (select ordem_id, sum(valor_centavos) v from devolucoes where status = 'concluida' group by ordem_id) d on d.ordem_id = o.id
            where o.unidade_id in ($in)".($responsavel ? ' and o.criado_por = '.(int) $responsavel : '')." and o.status <> 'cancelado' group by o.unidade_id");
        $divergenciasAbertas = $agr("select unidade_id, count(*) n from divergencias where unidade_id in ($in){$ord()} and resolvido_em is null group by unidade_id");

        $r = [];
        foreach ($unidadeIds as $id) {
            $r[$id] = [
                'entradas' => (int) ($entradas[$id]->n ?? 0), 'saidas' => (int) ($saidas[$id]->n ?? 0),
                'orcado' => (int) ($orcado[$id]->v ?? 0), 'aprovado' => (int) ($aprovado[$id]->v ?? 0), 'aprovacoes' => (int) ($aprovado[$id]->n ?? 0), 'recusas' => (int) ($recusas[$id]->n ?? 0),
                'recebido' => (int) ($recebido[$id]->v ?? 0) - (int) ($devolvido[$id]->v ?? 0), 'recebido_pix' => (int) ($recebido[$id]->pix ?? 0), 'recebido_manual' => (int) ($recebido[$id]->manual ?? 0),
                'devolvido' => (int) ($devolvido[$id]->v ?? 0), 'pendente' => (int) ($pendente[$id]->v ?? 0),
                'descontos' => (int) ($descontos[$id]->v ?? 0), 'descontos_qtd' => (int) ($descontos[$id]->n ?? 0),
                'cancelamentos' => (int) ($cancelamentos[$id] ?? 0), 'liberacoes' => (int) ($saidas[$id]->excecoes ?? 0), 'liberado_sem_quitacao' => (int) ($saidas[$id]->liberado ?? 0),
                'falhas' => (int) ($falhas[$id]->n ?? 0), 'divergencias_abertas' => (int) ($divergenciasAbertas[$id]->n ?? 0),
                'por_etapa' => collect($porEtapa[$id] ?? [])->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            ];
        }

        return $r;
    }
}
