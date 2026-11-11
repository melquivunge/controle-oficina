<?php

namespace App\Services;

use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\User;
use App\Support\Dinheiro;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * OS do sistema antigo → OS nova (decisão do usuário, 11/11/2026). Importável: não cancelada e (aberta sem quitação no
 * financeiro do SGA ou finalizada com valor em aberto no financeiro). Finalizada sem lançamento no financeiro não entra
 * (provavelmente paga no balcão). Entra pela Nova entrada; os itens viram rascunho do orçamento, com o mesmo total.
 */
class ImportacaoOsLegada
{
    /** @return array{importavel: bool, motivo: string, finalizada: bool, aberto: float, pago: float, ordem_id: ?int} */
    public function situacao(object $registro): array
    {
        $d = is_array($registro->dados) ? $registro->dados : json_decode($registro->dados, true);
        $finalizada = ($d['STATUS'] ?? 'A') === 'F';
        [$aberto, $pago, $temFinanceiro] = $this->financeiro((string) ($d['CHCOD_MOVIMENTO'] ?? ''));
        $base = ['finalizada' => $finalizada, 'aberto' => $aberto, 'pago' => $pago, 'ordem_id' => $registro->ordem_id ?? null];
        [$ok, $motivo] = match (true) {
            ($registro->ordem_id ?? null) !== null => [false, 'Já importada.'],
            ($d['CANCELADA'] ?? 'N') === 'S' => [false, 'OS cancelada no sistema antigo.'],
            ! $registro->cliente_id => [false, 'OS sem cliente ligado no cadastro novo.'],
            ! $finalizada && (! $temFinanceiro || $aberto > 0.009) => [true, 'Aberta no sistema antigo.'],
            $finalizada && $aberto > 0.009 => [true, 'Finalizada com valor em aberto no financeiro do sistema antigo.'],
            default => [false, $finalizada ? 'Finalizada e sem valor em aberto no financeiro do sistema antigo.' : 'Aberta, mas já quitada no financeiro do sistema antigo.'],
        };

        return $base + ['importavel' => $ok, 'motivo' => $motivo];
    }

    /** Ids (legado_registros) de todas as OS importáveis, com o financeiro carregado de uma vez (lista "Para importar"). */
    public function idsImportaveis(): array
    {
        $mov = DB::table('legado_registros')->where('tabela', 'TBL_MOVIMENTO')->pluck('dados')->map(fn ($x) => json_decode($x, true));
        $porCodigo = $mov->keyBy('COD_MOVIMENTO');
        $porGrupo = $mov->groupBy(fn ($m) => ($m['CH_MOV_PARC1'] ?? '') !== '' ? $m['CH_MOV_PARC1'] : $m['COD_MOVIMENTO']);
        $ids = [];
        foreach (DB::table('legado_registros')->where('tabela', 'TBL_OS')->whereNull('ordem_id')->whereNotNull('cliente_id')->get(['id', 'dados']) as $r) {
            $d = json_decode($r->dados, true);
            if (($d['CANCELADA'] ?? 'N') === 'S') {
                continue;
            }
            $m = $porCodigo->get((string) ($d['CHCOD_MOVIMENTO'] ?? ''));
            $parc = $m ? collect($porGrupo->get(($m['CH_MOV_PARC1'] ?? '') !== '' ? $m['CH_MOV_PARC1'] : $m['COD_MOVIMENTO'], []))->reject(fn ($p) => ($p['EXCLUSAO'] ?? 'N') === 'S') : null;
            $aberto = $parc ? $parc->filter(fn ($p) => ($p['BAIXA'] ?? 'N') !== 'S')->sum(fn ($p) => (float) ($p['VALOR_TOTAL'] ?? 0)) : 0;
            if ((($d['STATUS'] ?? 'A') !== 'F' && (! $m || $aberto > 0.009)) || (($d['STATUS'] ?? 'A') === 'F' && $aberto > 0.009)) {
                $ids[] = $r->id;
            }
        }

        return $ids;
    }

    /** Parcelas do lançamento (TBL_MOVIMENTO) ligado à OS: [em aberto, pago, existe]. */
    private function financeiro(string $mov): array
    {
        if ($mov === '') {
            return [0.0, 0.0, false];
        }
        $m = DB::table('legado_registros')->where('tabela', 'TBL_MOVIMENTO')->whereRaw("dados->>'COD_MOVIMENTO' = ?", [$mov])->value('dados');
        if (! $m) {
            return [0.0, 0.0, false];
        }
        $m = json_decode($m, true);
        $grupo = ($m['CH_MOV_PARC1'] ?? '') !== '' ? $m['CH_MOV_PARC1'] : $m['COD_MOVIMENTO'];
        $parcelas = DB::table('legado_registros')->where('tabela', 'TBL_MOVIMENTO')
            ->where(fn ($q) => $q->whereRaw("dados->>'COD_MOVIMENTO' = ?", [$grupo])->orWhereRaw("dados->>'CH_MOV_PARC1' = ?", [$grupo]))
            ->pluck('dados')->map(fn ($x) => json_decode($x, true))->reject(fn ($p) => ($p['EXCLUSAO'] ?? 'N') === 'S');
        $aberto = $parcelas->filter(fn ($p) => ($p['BAIXA'] ?? 'N') !== 'S')->sum(fn ($p) => (float) ($p['VALOR_TOTAL'] ?? 0));
        $pago = $parcelas->filter(fn ($p) => ($p['BAIXA'] ?? 'N') === 'S')->sum(fn ($p) => (float) ($p['VALOR_TOTAL'] ?? 0));

        return [round($aberto, 2), round($pago, 2), true];
    }

    /** Usado por Entradas::registrar dentro da transação. */
    public function travarParaEntrada(int $id): object
    {
        $r = DB::table('legado_registros')->where('tabela', 'TBL_OS')->where('id', $id)->lockForUpdate()->first();
        if (! $r || ! ($s = $this->situacao($r))['importavel']) {
            throw ValidationException::withMessages(['telefone' => 'Esta OS do sistema antigo não pode ser importada: '.($s['motivo'] ?? 'não encontrada.')]);
        }

        return $r;
    }

    /** Itens do SGA (serviço/peça) com o total exato de cada linha: QTD × PREÇO − DESCONTO. */
    public function itens(object $registro): array
    {
        $linhas = [];
        foreach (DB::table('legado_registros')->where('grupo', $registro->grupo)->whereIn('tabela', ['TBL_SERVICO', 'TBL_PECA'])->orderBy('tabela', 'desc')->orderBy('linha')->get() as $i) { // serviços antes das peças
            $d = json_decode($i->dados, true);
            $q = (float) ($d['QTD'] ?? 1) ?: 1.0;
            $total = (int) round(($q * (float) ($d['PRECO'] ?? 0) - (float) ($d['DESCONTO'] ?? 0)) * 100);
            $descricao = mb_substr(trim((string) ($d['DESCRICAO'] ?? '')) ?: 'Item do sistema antigo', 0, 280);
            $qtd = number_format($q, 3, '.', '');
            $unit = (int) round($total / $q);
            // Se quantidade × preço arredondado não fecha o total do SGA (desconto, fração), vira "Q x descrição" com quantidade 1.
            $exato = Dinheiro::totalItem($qtd, $unit) === $total;
            $linhas[] = ['descricao' => $exato ? $descricao : rtrim(rtrim(number_format($q, 3, ',', ''), '0'), ',').' x '.$descricao,
                'quantidade' => $exato ? $qtd : '1.000', 'preco_unitario_centavos' => $exato ? $unit : $total,
                'total_centavos' => $total, 'tipo' => $i->tabela === 'TBL_PECA' ? 'produto' : 'servico'];
        }

        return array_values(array_filter($linhas, fn ($l) => $l['total_centavos'] >= 0));
    }

    public function concluirEntrada(object $registro, Ordem $ordem, User $ator): void
    {
        $d = json_decode($registro->dados, true);
        $s = $this->situacao($registro);
        $itens = $this->itens($registro);
        $total = array_sum(array_column($itens, 'total_centavos'));
        $obs = "Importada da OS nº {$d['CODIGO']} do sistema antigo".($s['finalizada'] ? ' (finalizada lá).' : '.')
            .($s['pago'] > 0.009 ? ' Já pago no sistema antigo: '.Dinheiro::formatar((int) round($s['pago'] * 100)).'.' : '')
            .($s['aberto'] > 0.009 ? ' Em aberto no financeiro antigo: '.Dinheiro::formatar((int) round($s['aberto'] * 100)).'.' : '');
        if ($itens) {
            $orc = Orcamento::create(['ordem_id' => $ordem->id, 'unidade_id' => $ordem->unidade_id, 'versao' => 1, 'status' => 'rascunho', 'subtotal_centavos' => $total,
                'desconto_centavos' => 0, 'total_centavos' => $total, 'criado_por' => $ator->id, 'observacao_cliente' => $obs]);
            foreach ($itens as $n => $l) {
                $orc->itens()->create($l + ['posicao' => $n + 1]);
            }
        }
        DB::table('legado_registros')->where('id', $registro->id)->update(['ordem_id' => $ordem->id]);
        Auditoria::registrar('os_legada_importada', $ordem, depois: ['os_antiga' => $d['CODIGO'], 'itens' => count($itens), 'total_centavos' => $total,
            'aberto' => $s['aberto'], 'pago' => $s['pago']], unidadeId: $ordem->unidade_id, ator: $ator);
    }
}
