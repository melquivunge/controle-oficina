<?php

namespace App\Services;

use App\Enums\StatusOrdem;
use App\Models\Devolucao;
use App\Models\Empresa;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Support\Fuso;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exportação do painel em Excel (.xlsx): por unidade, por dia, por dia da semana, lista de ordens e de recebimentos (06/11/2026),
 * cada aba com linha TOTAL. Uma loja (filtro) ou todas juntas.
 * Valores em número de verdade (R$ formatado pela célula), não texto, para somar/filtrar no Excel.
 * Texto sempre como StringCell: nome começando com "=" nunca vira fórmula (injeção em planilha).
 */
final class PainelExcel
{
    private const NAVY = '0F1B2D';
    private const ZEBRA = 'F3F5F9';
    private const TOTAL = 'E3F6EE';
    private const MOEDA = '"R$" #,##0.00';
    private const INTEIRO = '#,##0';

    /** Colunas da aba por unidade: chave => [rótulo, é dinheiro]. */
    public const COLUNAS_UNIDADE = [
        'entradas' => ['Entradas', false], 'saidas' => ['Entregas', false], 'orcado' => ['Orçado', true], 'aprovado' => ['Aprovado', true],
        'recebido' => ['Recebido', true], 'recebido_pix' => ['Recebido Pix', true], 'recebido_manual' => ['Recebido manual', true],
        'devolvido' => ['Devolvido', true], 'pendente' => ['Saldo em aberto', true], 'descontos' => ['Descontos', true],
        'cancelamentos' => ['Cancelamentos', false], 'liberacoes' => ['Entregas sem quitação', false], 'falhas' => ['Falhas e revisões', false],
        'divergencias_abertas' => ['Divergências abertas', false],
    ];

    /**
     * @param  iterable<\App\Models\Unidade>  $unidades
     * @param  array<int, array<string, int>>  $linhas  Indicadores::porUnidade
     * @param  array{dias: list<string>, entradas: list<int>, entregas: list<int>, aprovado: list<int>, recebido: list<int>}  $serie
     * @param  array{dias: array<int, array<string, mixed>>}  $semana
     */
    public function gravar(string $arquivo, iterable $unidades, array $linhas, array $serie, array $semana, string $de, string $ate, string $unidade, string $responsavel, ?int $responsavelId = null): void
    {
        $opcoes = new Options;
        // ponytail: largura vale para todas as abas (1ª coluna = rótulo, demais numéricas); por aba só se algum dia precisar.
        $opcoes->setColumnWidth(28, 1);
        $opcoes->setColumnWidthForRange(17, 2, 1 + count(self::COLUNAS_UNIDADE));
        $writer = new Writer($opcoes);
        $nome = Empresa::atual()->nomeSistema();
        $writer->setCreator($nome);
        $writer->openToFile($arquivo);
        $periodo = CarbonImmutable::parse($de)->format('d/m/Y').' a '.CarbonImmutable::parse($ate)->format('d/m/Y');
        $filtros = "Período {$periodo} · Unidade: {$unidade} · Responsável (entrada): ".($responsavel ?: 'Todos').' · Gerado em '.Fuso::exibir(now(), 'd/m/Y H:i');

        // Aba 1: por unidade
        $cols = self::COLUNAS_UNIDADE;
        $this->cabecalho($writer, $nome, 'Resumo por unidade', $filtros, ['Unidade', ...array_column($cols, 0)]);
        $total = array_fill_keys(array_keys($cols), 0);
        $i = 0;
        foreach ($unidades as $u) {
            $valores = [];
            foreach ($cols as $k => [, $din]) {
                $v = (int) ($linhas[$u->id][$k] ?? 0);
                $total[$k] += $v;
                $valores[] = [$din ? $v / 100 : $v, $din];
            }
            $this->linha($writer, $u->nome, $valores, $i++ % 2 === 1);
        }
        $this->linha($writer, 'TOTAL', array_map(fn ($k) => [$cols[$k][1] ? $total[$k] / 100 : $total[$k], $cols[$k][1]], array_keys($cols)), false, true);

        // Aba 2: por dia
        $writer->addNewSheetAndMakeItCurrent();
        $this->cabecalho($writer, $nome, 'Por dia', $filtros, ['Data', 'Entradas', 'Entregas', 'Aprovado', 'Recebido']);
        $t = ['entradas' => 0, 'entregas' => 0, 'aprovado' => 0, 'recebido' => 0];
        foreach ($serie['dias'] as $n => $dia) {
            foreach ($t as $k => $_) {
                $t[$k] += (int) $serie[$k][$n];
            }
            $this->linha($writer, CarbonImmutable::parse($dia)->format('d/m/Y'), [[(int) $serie['entradas'][$n], false], [(int) $serie['entregas'][$n], false],
                [$serie['aprovado'][$n] / 100, true], [$serie['recebido'][$n] / 100, true]], $n % 2 === 1);
        }
        $this->linha($writer, 'TOTAL', [[$t['entradas'], false], [$t['entregas'], false], [$t['aprovado'] / 100, true], [$t['recebido'] / 100, true]], false, true);

        // Aba 3: por dia da semana
        $writer->addNewSheetAndMakeItCurrent();
        $this->cabecalho($writer, $nome, 'Dia da semana', $filtros, ['Dia', 'Recebido', 'Entradas', 'Dias no período', 'Dias sem venda', 'Média recebida por dia']);
        $ts = ['recebido' => 0, 'entradas' => 0, 'ocorrencias' => 0, 'sem_venda' => 0];
        $i = 0;
        foreach ($semana['dias'] as $l) {
            foreach ($ts as $k => $_) {
                $ts[$k] += (int) $l[$k];
            }
            $this->linha($writer, $l['rotulo'], [[$l['recebido'] / 100, true], [(int) $l['entradas'], false], [(int) $l['ocorrencias'], false], [(int) $l['sem_venda'], false], [$l['media'] / 100, true]], $i++ % 2 === 1);
        }
        $media = $ts['ocorrencias'] ? $ts['recebido'] / $ts['ocorrencias'] : 0;
        $this->linha($writer, 'TOTAL', [[$ts['recebido'] / 100, true], [$ts['entradas'], false], [$ts['ocorrencias'], false], [$ts['sem_venda'], false], [$media / 100, true]], false, true);

        $nomes = collect($unidades)->pluck('nome', 'id');
        $ids = $nomes->keys()->all();
        $inicio = Fuso::inicioDoDia($de);
        $fim = Fuso::inicioDoDia($ate)->addDay();
        $doResponsavel = fn ($q, string $col = 'criado_por') => $q->when($responsavelId, fn ($q) => $q->where($col, $responsavelId));

        // Aba 4: ordens que entraram no período. Recebido/saldo = situação atual da OS (todos os pagamentos e devoluções).
        $writer->addNewSheetAndMakeItCurrent();
        $this->cabecalho($writer, $nome, 'Ordens', $filtros, ['OS', 'Loja', 'Entrada', 'Cliente', 'Veículo / item', 'Situação', 'Aprovado', 'Recebido', 'Saldo']);
        $t = [0, 0, 0];
        $ordens = Ordem::with('cliente:id,nome', 'veiculo')->whereIn('unidade_id', $ids)
            ->where('entrada_em', '>=', $inicio)->where('entrada_em', '<', $fim)->tap($doResponsavel)
            ->addSelect(['aprovado_c' => Orcamento::selectRaw('coalesce(sum(total_centavos),0)')->whereColumn('ordem_id', 'ordens.id')->where('status', 'aprovado'),
                'pago_c' => Pagamento::selectRaw('coalesce(sum(valor_centavos),0)')->whereColumn('ordem_id', 'ordens.id')->whereIn('status', ['confirmado', 'registrado']),
                'devolvido_c' => Devolucao::selectRaw('coalesce(sum(valor_centavos),0)')->whereColumn('ordem_id', 'ordens.id')->where('status', 'concluida')])
            ->orderBy('entrada_em')->orderBy('id');
        $i = 0;
        foreach ($ordens->lazy(500) as $o) {
            $aprovado = (int) $o->aprovado_c;
            $recebido = (int) $o->pago_c - (int) $o->devolvido_c;
            $saldo = $o->status === StatusOrdem::Cancelado ? 0 : max(0, $aprovado - $recebido);
            $t = [$t[0] + $aprovado, $t[1] + $recebido, $t[2] + $saldo];
            $this->linha($writer, $o->numero, [$nomes[$o->unidade_id], Fuso::exibir($o->entrada_em, 'd/m/Y'), $o->cliente->nome,
                trim($o->identificacao().' '.($o->veiculo?->modelo ?? '')), $o->status->rotulo(), [$aprovado / 100, true], [$recebido / 100, true], [$saldo / 100, true]], $i++ % 2 === 1);
        }
        $this->linha($writer, 'TOTAL', ["{$i} ordens", '', '', '', '', [$t[0] / 100, true], [$t[1] / 100, true], [$t[2] / 100, true]], false, true);

        // Aba 5: recebimentos efetivos no período (Pix confirmado e registros manuais) e devoluções concluídas (negativas).
        $writer->addNewSheetAndMakeItCurrent();
        $this->cabecalho($writer, $nome, 'Recebimentos', $filtros, ['Data', 'Loja', 'OS', 'Cliente', 'Forma', 'Valor']);
        $daOrdem = fn ($q) => $q->whereHas('ordem', fn ($q) => $doResponsavel($q));
        $linhas = Pagamento::with('ordem:id,numero,cliente_id', 'ordem.cliente:id,nome')->whereIn('unidade_id', $ids)->whereIn('status', ['confirmado', 'registrado'])
            ->where('recebido_em', '>=', $inicio)->where('recebido_em', '<', $fim)->when($responsavelId, $daOrdem)->get()
            ->map(fn ($p) => [$p->recebido_em, $nomes[$p->unidade_id], $p->ordem->numero, $p->ordem->cliente->nome, ['pix' => 'Pix', 'pix_manual' => 'Pix (comprovante)', 'dinheiro' => 'Dinheiro', 'cartao_externo' => 'Cartão'][$p->meio] ?? $p->meio, $p->valor_centavos])
            ->concat(Devolucao::with('ordem:id,numero,cliente_id', 'ordem.cliente:id,nome')->whereIn('unidade_id', $ids)->where('status', 'concluida')
                ->where('concluida_em', '>=', $inicio)->where('concluida_em', '<', $fim)->when($responsavelId, $daOrdem)->get()
                ->map(fn ($d) => [$d->concluida_em, $nomes[$d->unidade_id], $d->ordem->numero, $d->ordem->cliente->nome, 'Devolução', -$d->valor_centavos]))
            ->sortBy(fn ($l) => $l[0]->getTimestamp())->values();
        $soma = 0;
        foreach ($linhas as $n => [$quando, $loja, $os, $cliente, $forma, $valor]) {
            $soma += $valor;
            $this->linha($writer, Fuso::exibir($quando, 'd/m/Y H:i'), [$loja, $os, $cliente, $forma, [$valor / 100, true]], $n % 2 === 1);
        }
        $this->linha($writer, 'TOTAL', [$linhas->count().' lançamentos', '', '', '', [$soma / 100, true]], false, true);

        $writer->close();
    }

    /** Título, linha de filtros, linha vazia e cabeçalho navy (linhas 1–4 congeladas). */
    private function cabecalho(Writer $writer, string $nome, string $aba, string $filtros, array $titulos): void
    {
        $writer->getCurrentSheet()->setName($aba)->setSheetView((new SheetView)->setFreezeRow(5)->setShowGridLines(false));
        $writer->addRow((new Row([new StringCell("{$nome} — {$aba}", (new Style)->setFontBold()->setFontSize(16)->setFontColor(self::NAVY))]))->setHeight(26));
        $writer->addRow(new Row([new StringCell($filtros, (new Style)->setFontSize(10)->setFontColor('667085'))]));
        $writer->addRow(Row::fromValues([]));
        $celulas = [];
        foreach ($titulos as $n => $t) {
            $estilo = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor(self::NAVY)->setShouldWrapText()
                ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)->setBorder($this->borda('FFFFFF'));
            $celulas[] = new StringCell($t, $n === 0 ? $estilo : $estilo->setCellAlignment(CellAlignment::RIGHT));
        }
        $writer->addRow((new Row($celulas))->setHeight(32));
    }

    /** @param list<string|array{0: int|float, 1: bool}> $valores texto ou [valor, é dinheiro] */
    private function linha(Writer $writer, string $rotulo, array $valores, bool $zebra, bool $total = false): void
    {
        $estilo = function () use ($zebra, $total): Style {
            $s = (new Style)->setBorder($this->borda($total ? self::NAVY : 'E4E8EF', $total));
            if ($total) {
                $s->setFontBold()->setBackgroundColor(self::TOTAL)->setFontColor(self::NAVY);
            } elseif ($zebra) {
                $s->setBackgroundColor(self::ZEBRA);
            }

            return $s;
        };
        $celulas = [new StringCell($rotulo, $estilo())];
        foreach ($valores as $c) {
            $celulas[] = is_string($c) ? new StringCell($c, $estilo()) : Cell::fromValue($c[0], $estilo()->setFormat($c[1] ? self::MOEDA : self::INTEIRO));
        }
        $writer->addRow((new Row($celulas))->setHeight(20));
    }

    private function borda(string $cor, bool $topoForte = false): Border
    {
        return new Border(
            new BorderPart(Border::BOTTOM, $cor, Border::WIDTH_THIN),
            new BorderPart(Border::TOP, $cor, $topoForte ? Border::WIDTH_MEDIUM : Border::WIDTH_THIN),
        );
    }
}
