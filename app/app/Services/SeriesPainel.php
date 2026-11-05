<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Séries diárias (dia local de Brasília) para os gráficos do painel. Valores em centavos. */
class SeriesPainel
{
    /** @return array{dias: list<string>, entradas: list<int>, entregas: list<int>, aprovado: list<int>, recebido: list<int>, por_meio: array<string,int>} */
    public function diarias(array $unidadeIds, string $de, string $ate, CarbonImmutable $inicio, CarbonImmutable $fim, ?int $responsavel = null): array
    {
        // Filtro por quem registrou a entrada (ordens.criado_por); inteiro convertido, seguro para interpolar.
        $ord = $responsavel ? ' and ordem_id in (select id from ordens where criado_por = '.(int) $responsavel.')' : '';
        $criador = $responsavel ? ' and criado_por = '.(int) $responsavel : '';
        $in = implode(',', array_map('intval', $unidadeIds ?: [0]));
        $tz = "at time zone 'America/Sao_Paulo'";
        $p = [$inicio, $fim];
        $linhas = DB::select("select d::date dia,
                coalesce(e.n,0) entradas, coalesce(s.n,0) entregas, coalesce(a.v,0) aprovado, coalesce(r.v,0) - coalesce(dv.v,0) recebido
            from generate_series(?::date, ?::date, interval '1 day') d
            left join (select (entrada_em $tz)::date dia, count(*) n from ordens where unidade_id in ($in)$criador and entrada_em >= ? and entrada_em < ? group by 1) e on e.dia = d::date
            left join (select (entregue_em $tz)::date dia, count(*) n from entregas where unidade_id in ($in)$ord and entregue_em >= ? and entregue_em < ? group by 1) s on s.dia = d::date
            left join (select (decidido_em $tz)::date dia, sum(total_centavos) v from aprovacoes where unidade_id in ($in)$ord and decisao = 'aprovado' and decidido_em >= ? and decidido_em < ? group by 1) a on a.dia = d::date
            left join (select (recebido_em $tz)::date dia, sum(valor_centavos) v from pagamentos where unidade_id in ($in)$ord and status in ('confirmado','registrado') and recebido_em >= ? and recebido_em < ? group by 1) r on r.dia = d::date
            left join (select (concluida_em $tz)::date dia, sum(valor_centavos) v from devolucoes where unidade_id in ($in)$ord and status = 'concluida' and concluida_em >= ? and concluida_em < ? group by 1) dv on dv.dia = d::date
            order by 1", [$de, $ate, ...$p, ...$p, ...$p, ...$p, ...$p]);
        $porMeio = collect(DB::select("select meio, sum(valor_centavos) v from pagamentos where unidade_id in ($in)$ord and status in ('confirmado','registrado') and recebido_em >= ? and recebido_em < ? group by meio", $p))
            ->pluck('v', 'meio')->map(fn ($v) => (int) $v)->all();

        $col = fn ($k) => array_map(fn ($l) => (int) $l->$k, $linhas);

        return ['dias' => array_map(fn ($l) => (string) $l->dia, $linhas), 'entradas' => $col('entradas'), 'entregas' => $col('entregas'),
            'aprovado' => $col('aprovado'), 'recebido' => $col('recebido'), 'por_meio' => $porMeio];
    }

    public const DIAS_SEMANA = [1 => ['Segunda', 'Seg'], 2 => ['Terça', 'Ter'], 3 => ['Quarta', 'Qua'], 4 => ['Quinta', 'Qui'], 5 => ['Sexta', 'Sex'], 6 => ['Sábado', 'Sáb'], 7 => ['Domingo', 'Dom']];

    /**
     * Vendas por dia da semana a partir da série diária (mesmos filtros de unidade/responsável/período).
     * Ranking pela média por ocorrência do dia no período (30 dias têm 4 ou 5 de cada dia). "Sem venda" = recebido ≤ 0
     * no dia (ou, para quem não vê valores, nenhuma entrada).
     *
     * @return array{dias: array<int, array{rotulo:string, curto:string, recebido:int, entradas:int, ocorrencias:int, sem_venda:int, media:int}>, melhor: ?int, pior: ?int, sem_venda: int, total_dias: int, metrica: string}
     */
    public static function porDiaDaSemana(array $serie, bool $valores = true): array
    {
        $metrica = $valores ? 'recebido' : 'entradas';
        $dias = [];
        foreach (self::DIAS_SEMANA as $n => [$rotulo, $curto]) {
            $dias[$n] = ['rotulo' => $rotulo, 'curto' => $curto, 'recebido' => 0, 'entradas' => 0, 'ocorrencias' => 0, 'sem_venda' => 0, 'media' => 0];
        }
        $semVenda = 0;
        foreach ($serie['dias'] as $i => $d) {
            $n = CarbonImmutable::parse($d)->dayOfWeekIso;
            $dias[$n]['recebido'] += $serie['recebido'][$i];
            $dias[$n]['entradas'] += $serie['entradas'][$i];
            $dias[$n]['ocorrencias']++;
            if ($serie[$metrica][$i] <= 0) {
                $dias[$n]['sem_venda']++;
                $semVenda++;
            }
        }
        $medias = [];
        foreach ($dias as $n => &$l) {
            if ($l['ocorrencias'] > 0) {
                $l['media'] = intdiv($l[$metrica], $l['ocorrencias']);
                $medias[$n] = $l[$metrica] / $l['ocorrencias'];
            }
        }
        unset($l);
        // Sem diferença entre os dias (ex.: período sem nenhuma venda) não há melhor nem pior.
        $melhor = $pior = null;
        if ($medias !== [] && max($medias) > min($medias)) {
            $melhor = array_search(max($medias), $medias, true);
            $pior = array_search(min($medias), $medias, true);
        }

        return ['dias' => $dias, 'melhor' => $melhor, 'pior' => $pior, 'sem_venda' => $semVenda, 'total_dias' => count($serie['dias']), 'metrica' => $metrica];
    }

    /** Métricas do gráfico do painel: rótulo, título, coluna de data correspondente na lista de ordens e se é dinheiro. */
    public const METRICAS_GRAFICO = [
        'recebido' => ['Recebido', 'Recebido no período', 'pagamento', true],
        'aprovado' => ['Aprovado', 'Aprovado no período', 'aprovacao', true],
        'entradas' => ['Entradas', 'Entradas no período', 'entrada', false],
        'entregas' => ['Entregas', 'Entregas no período', 'entrega', false],
    ];

    /**
     * Dados do gráfico interativo "Recebido no período": grupos (dia/semana/mês) com intervalo de datas recortado ao período
     * e as séries por métrica. Sem $dinheiro, as séries em dinheiro (recebido/aprovado) não entram no resultado — o que não
     * está aqui não vai para a página.
     *
     * @return array{escala: string, padrao: string, grupos: list<array{chave:string, rotulo:string, titulo:string, de:string, ate:string}>, entradas: list<int>, metricas: array<string, array{rotulo:string, titulo:string, data:string, moeda:bool, valores:list<int>, curtos:list<string>, textos:list<string>, total:string}>}
     */
    public static function grafico(array $serie, bool $dinheiro, string $de, string $ate): array
    {
        $n = count($serie['dias']);
        $escala = $n > 92 ? 'mês' : ($n > 31 ? 'semana' : 'dia');
        $base = self::agrupar($serie['dias'], $serie['entradas']);
        $chaves = array_keys($base);
        $limite = fn (CarbonImmutable $d) => $d->format('Y-m-d');
        $dm = fn (string $d) => CarbonImmutable::parse($d)->format('d/m');
        $grupos = [];
        foreach ($chaves as $chave) {
            if ($escala === 'mês') {
                $ini = CarbonImmutable::parse($chave.'-01');
                $gDe = max($limite($ini), $de);
                $gAte = min($limite($ini->endOfMonth()), $ate);
                $mes = \Illuminate\Support\Str::ucfirst($ini->locale('pt_BR')->isoFormat('MMMM [de] YYYY'));
                $titulo = ($gDe === $limite($ini) && $gAte === $limite($ini->endOfMonth())) ? $mes : $mes.' · '.$dm($gDe).' a '.$dm($gAte);
                $rotulo = \Illuminate\Support\Str::ucfirst($ini->locale('pt_BR')->isoFormat(count($chaves) > 12 ? 'MMM/YY' : 'MMM'));
            } elseif ($escala === 'semana') {
                $ini = CarbonImmutable::parse($chave);
                $gDe = max($chave, $de);
                $gAte = min($limite($ini->addDays(6)), $ate);
                $titulo = 'Semana de '.$dm($gDe).' a '.CarbonImmutable::parse($gAte)->format('d/m/Y');
                $rotulo = $ini->format('d/m');
            } else {
                $d = CarbonImmutable::parse($chave);
                $gDe = $gAte = $chave;
                $titulo = self::DIAS_SEMANA[$d->dayOfWeekIso][1].', '.$d->format('d/m/Y');
                $rotulo = $d->format('d/m');
            }
            $grupos[] = ['chave' => $chave, 'rotulo' => $rotulo, 'titulo' => $titulo, 'de' => $gDe, 'ate' => $gAte];
        }

        $nomes = ['entradas' => ['entrada', 'entradas'], 'entregas' => ['entrega', 'entregas']];
        $metricas = [];
        foreach (self::METRICAS_GRAFICO as $m => [$rotulo, $titulo, $data, $moeda]) {
            if ($moeda && ! $dinheiro) {
                continue;
            }
            $valores = array_values(self::agrupar($serie['dias'], $serie[$m]));
            $fmt = $moeda ? fn (int $v) => \App\Support\Dinheiro::formatar($v) : fn (int $v) => number_format($v, 0, ',', '.');
            $metricas[$m] = [
                'rotulo' => $rotulo, 'titulo' => $titulo, 'data' => $data, 'moeda' => $moeda, 'valores' => $valores,
                'curtos' => array_map($fmt, $valores),
                'textos' => array_map(fn (int $v) => $moeda ? $rotulo.' '.$fmt($v) : $fmt($v).' '.$nomes[$m][$v === 1 ? 0 : 1], $valores),
                'total' => $fmt(array_sum($serie[$m])),
            ];
        }

        return ['escala' => $escala, 'padrao' => $dinheiro ? 'recebido' : 'entradas', 'grupos' => $grupos,
            'entradas' => array_values($base), 'metricas' => $metricas];
    }

    /** Agrupa uma série diária em semanas ou meses quando o período é longo (para o gráfico de barras). */
    public static function agrupar(array $dias, array $valores): array
    {
        $n = count($dias);
        $chave = $n > 92 ? fn ($d) => substr($d, 0, 7) : ($n > 31 ? fn ($d) => CarbonImmutable::parse($d)->startOfWeek()->format('Y-m-d') : fn ($d) => $d);
        $g = [];
        foreach ($dias as $i => $d) {
            $g[$chave($d)] = ($g[$chave($d)] ?? 0) + $valores[$i];
        }

        return $g;
    }
}
