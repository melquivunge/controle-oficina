<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\Indicadores;
use App\Services\PainelExcel;
use App\Services\SeriesPainel;
use App\Support\Fuso;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Painel: operação do momento para todos; números do período (financeiros) só para quem vê indicadores. */
class PainelController extends Controller
{
    public const PERIODOS = ['7' => '7 dias', '30' => '30 dias', '365' => '12 meses', '730' => '2 anos'];

    /** Período máximo (dias entre de e até): 2 anos, incluindo um 29 de fevereiro. */
    public const MAX_DIAS = 731;

    public function __invoke(Request $request, Indicadores $indicadores, SeriesPainel $series)
    {
        $user = $request->user();
        if ($user->papel === Papel::Mecanico) {
            return redirect()->route('painel.mecanico');
        }
        [$unidadeId, $unidades, $de, $ate, $periodo, $responsavelId, $responsaveis] = $this->filtros($request);
        $ids = $unidadeId ? [$unidadeId] : $unidades->pluck('id')->all();
        if ($user->painel_simples && $user->can('ver_auditoria')) {
            return $this->simples($user, $unidades->whereIn('id', $ids)->values(), $indicadores);
        }

        $abertas = Ordem::visiveisPara($user)->with('cliente', 'veiculo', 'unidade')
            ->whereIn('status', StatusOrdem::abertas())
            ->when($unidadeId, fn ($q) => $q->where('ordens.unidade_id', $unidadeId))
            ->when($responsavelId, fn ($q) => $q->where('ordens.criado_por', $responsavelId));
        $dados = [
            'ordens' => (clone $abertas)->orderByDesc('entrada_em')->limit(8)->get(),
            'porStatus' => (clone $abertas)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'unidades' => $unidades, 'unidadeId' => $unidadeId, 'de' => $de, 'ate' => $ate, 'periodo' => $periodo,
            'responsavelId' => $responsavelId, 'responsaveis' => $responsaveis,
            // Bloco A: entregas com prazo vencido (mesmos filtros de unidade/responsável da lista de abertas).
            'atrasadas' => app(\App\Services\PrazosEntrega::class)->consultaAtrasadas($user)
                ->when($unidadeId, fn ($q) => $q->where('ordens.unidade_id', $unidadeId))
                ->when($responsavelId, fn ($q) => $q->where('ordens.criado_por', $responsavelId))->count(),
        ];

        $inicio = Fuso::inicioDoDia($de);
        $fim = Fuso::inicioDoDia($ate)->addDay();
        $dias = $inicio->diffInDays($fim);
        $dados['serie'] = $series->diarias($ids, $de, $ate, $inicio, $fim, $responsavelId);
        // Vendas por dia da semana: valores só para quem vê indicadores financeiros; demais veem entradas.
        $dados['semana'] = SeriesPainel::porDiaDaSemana($dados['serie'], $user->can('ver_auditoria'));
        if ($user->can('ver_auditoria')) {
            $dados['linhas'] = $indicadores->porUnidade($ids, $inicio, $fim, $responsavelId);
            $dados['total'] = $this->somar($dados['linhas']);
            $dados['anterior'] = $this->somar($indicadores->porUnidade($ids, $inicio->subDays($dias), $inicio, $responsavelId));
        }

        if (\App\Models\WhatsappConversa::atende($user) || \App\Models\WhatsappConversa::fazTriagem($user)) {
            $dados['whatsapp'] = app(\App\Services\WhatsApp\Atrasos::class)->indicadores($user);
        }

        return view('painel', $dados);
    }

    /** Liga/desliga a versão simples do painel (preferência do próprio usuário; rota exige ver_auditoria). */
    public function modo(Request $request)
    {
        $simples = $request->validate(['simples' => ['required', 'boolean']])['simples'];
        $user = $request->user();
        $antes = $user->only('painel_simples');
        $user->forceFill(['painel_simples' => (bool) $simples])->save();
        Auditoria::registrar('painel_modo_alterado', $user, $antes, $user->only('painel_simples'), ator: $user);

        return redirect()->route('painel');
    }

    /** Versão simples: "como estão as lojas hoje e o que precisa ser feito", com os mesmos escopos de unidade do painel. */
    private function simples(User $user, $unidades, Indicadores $indicadores)
    {
        $ids = $unidades->pluck('id')->all();
        $hoje = Fuso::exibir(now(), 'Y-m-d');
        $linhas = $indicadores->porUnidade($ids, Fuso::inicioDoDia($hoje), Fuso::inicioDoDia($hoje)->addDay());
        $atrasadas = app(\App\Services\PrazosEntrega::class)->consultaAtrasadas($user)->whereIn('ordens.unidade_id', $ids ?: [0])
            ->toBase()->selectRaw('ordens.unidade_id, count(*) n')->groupBy('ordens.unidade_id')->pluck('n', 'unidade_id');
        $lojas = $unidades->map(function ($u) use ($linhas, $atrasadas) {
            $l = $linhas[$u->id];
            $e = $l['por_etapa'];

            return ['unidade' => $u, 'na_oficina' => array_sum($e), 'prontos' => $e['pronto_para_entrega'] ?? 0,
                'aguardando' => $e['aguardando_aprovacao'] ?? 0, 'atrasadas' => (int) ($atrasadas[$u->id] ?? 0),
                'entradas' => $l['entradas'], 'saidas' => $l['saidas'], 'recebido' => $l['recebido'], 'pendente' => $l['pendente'],
                'divergencias' => $l['divergencias_abertas'], 'falhas' => $l['falhas']];
        });
        $soma = fn (string $k) => $lojas->sum($k);
        $pendencias = $user->can('resolver_divergencias') ? app(\App\Services\Pendencias::class)->listar($user)->count() : 0;

        return view('painel-simples', compact('lojas', 'soma', 'pendencias', 'hoje'));
    }

    public function exportar(Request $request, Indicadores $indicadores, SeriesPainel $series)
    {
        [$unidadeId, $unidades, $de, $ate, , $responsavelId, $responsaveis] = $this->filtros($request);
        if ($request->query('visao') === 'dia_semana') {
            return $this->exportarDiaSemana($series, $unidadeId ? [$unidadeId] : $unidades->pluck('id')->all(), $de, $ate, $responsavelId,
                $responsavelId ? $responsaveis->firstWhere('id', $responsavelId)->name : '', $unidadeId ? $unidades->firstWhere('id', $unidadeId)->nome : 'Todas');
        }
        $lista = $unidadeId ? $unidades->where('id', $unidadeId) : $unidades;
        $linhas = $indicadores->porUnidade($lista->pluck('id')->all(), Fuso::inicioDoDia($de), Fuso::inicioDoDia($ate)->addDay(), $responsavelId);
        $responsavel = $responsavelId ? $responsaveis->firstWhere('id', $responsavelId)->name : '';
        if ($request->query('formato') === 'xlsx') {
            // Excel com totais e formatação: mesmos filtros e mesma autorização (can:ver_auditoria na rota) do CSV.
            $ids = $lista->pluck('id')->all();
            $serie = $series->diarias($ids, $de, $ate, Fuso::inicioDoDia($de), Fuso::inicioDoDia($ate)->addDay(), $responsavelId);
            $arquivo = tempnam(sys_get_temp_dir(), 'painel');
            app(PainelExcel::class)->gravar($arquivo, $lista, $linhas, $serie, SeriesPainel::porDiaDaSemana($serie), $de, $ate,
                $unidadeId ? $unidades->firstWhere('id', $unidadeId)->nome : 'Todas', $responsavel);

            return response()->download($arquivo, "painel-{$de}-a-{$ate}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
        }
        $campos = ['entradas', 'saidas', 'orcado', 'aprovado', 'recebido', 'recebido_pix', 'recebido_manual', 'devolvido', 'pendente', 'descontos', 'cancelamentos', 'liberacoes', 'falhas', 'divergencias_abertas'];
        $centavos = ['orcado', 'aprovado', 'recebido', 'recebido_pix', 'recebido_manual', 'devolvido', 'pendente', 'descontos'];

        return response()->streamDownload(function () use ($lista, $linhas, $campos, $centavos, $de, $ate, $responsavel) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reconhece UTF-8
            fputcsv($out, ['unidade', 'de', 'ate', 'responsavel_entrada', ...$campos], ';');
            foreach ($lista as $u) {
                $valores = array_map(fn ($c) => in_array($c, $centavos, true) ? number_format($linhas[$u->id][$c] / 100, 2, ',', '') : $linhas[$u->id][$c], $campos);
                $esc = fn (string $t) => preg_replace('/^[=+\-@]/', "'$0", $t);
                fputcsv($out, [$esc($u->nome), $de, $ate, $esc($responsavel), ...$valores], ';');
            }
            fclose($out);
        }, "painel-{$de}-a-{$ate}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: ?int, 1: \Illuminate\Support\Collection, 2: string, 3: string, 4: ?string, 5: ?int, 6: \Illuminate\Support\Collection} */
    private function filtros(Request $request): array
    {
        $user = $request->user();
        $f = $request->validate([
            'unidade' => ['nullable', 'integer'], 'periodo' => ['nullable', 'in:'.implode(',', array_keys(self::PERIODOS))],
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
            'responsavel' => ['nullable', 'integer'],
        ]);
        $unidadeId = ! empty($f['unidade']) ? (int) $f['unidade'] : null;
        abort_if($unidadeId && ! $user->acessaUnidade($unidadeId), 403);
        $periodo = empty($f['de']) ? ($f['periodo'] ?? '30') : null;
        $ate = $f['ate'] ?? Fuso::exibir(now(), 'Y-m-d');
        $de = $f['de'] ?? CarbonImmutable::parse($ate)->subDays((int) $periodo - 1)->format('Y-m-d');
        abort_if(CarbonImmutable::parse($de)->diffInDays(CarbonImmutable::parse($ate)) > self::MAX_DIAS, 422, 'Período máximo de 2 anos.');

        $unidades = Unidade::visiveisPara($user)->where('ativa', true)->orderBy('nome')->get();
        // Responsável (entrada) = quem registrou a OS. Só recepção/gerência ativas das unidades visíveis (proprietário: todas).
        // Supervisor também registra entradas nas unidades que acompanha (usuario_unidades).
        $ids = $unidades->pluck('id');
        $responsaveis = User::where('ativo', true)->where(fn ($q) => $q
            ->where(fn ($q) => $q->whereIn('papel', [Papel::Recepcao->value, Papel::Gerente->value])->whereIn('unidade_id', $ids))
            ->orWhere(fn ($q) => $q->where('papel', Papel::Supervisor->value)->whereExists(fn ($s) => $s->from('usuario_unidades')
                ->whereColumn('usuario_unidades.user_id', 'users.id')->whereIn('usuario_unidades.unidade_id', $ids))))
            ->orderBy('name')->get(['id', 'name', 'unidade_id']);
        $responsavelId = ! empty($f['responsavel']) ? (int) $f['responsavel'] : null;
        abort_if($responsavelId && ! $responsaveis->contains('id', $responsavelId), 403, 'Responsável fora das unidades que você pode ver.');

        return [$unidadeId, $unidades, $de, $ate, $periodo, $responsavelId, $responsaveis];
    }

    /** CSV "Vendas por dia da semana" (mesmos filtros do painel). */
    private function exportarDiaSemana(SeriesPainel $series, array $ids, string $de, string $ate, ?int $responsavelId, string $responsavel, string $unidade)
    {
        $semana = SeriesPainel::porDiaDaSemana($series->diarias($ids, $de, $ate, Fuso::inicioDoDia($de), Fuso::inicioDoDia($ate)->addDay(), $responsavelId));
        $esc = fn (string $t) => preg_replace('/^[=+\-@]/', "'$0", $t);

        return response()->streamDownload(function () use ($semana, $de, $ate, $responsavel, $unidade, $esc) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['dia_semana', 'unidade', 'de', 'ate', 'responsavel_entrada', 'recebido', 'entradas', 'dias_no_periodo', 'dias_sem_venda', 'media_recebido_por_dia', 'destaque'], ';');
            foreach ($semana['dias'] as $n => $l) {
                $destaque = $n === $semana['melhor'] ? 'melhor' : ($n === $semana['pior'] ? 'pior' : '');
                fputcsv($out, [$l['rotulo'], $esc($unidade), $de, $ate, $esc($responsavel), number_format($l['recebido'] / 100, 2, ',', ''), $l['entradas'],
                    $l['ocorrencias'], $l['sem_venda'], number_format($l['media'] / 100, 2, ',', ''), $destaque], ';');
            }
            fclose($out);
        }, "painel-dia-semana-{$de}-a-{$ate}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function somar(array $linhas): array
    {
        $t = Indicadores::zerada();
        foreach ($linhas as $l) {
            foreach ($l as $k => $v) {
                if ($k !== 'por_etapa') {
                    $t[$k] += $v;
                }
            }
        }

        return $t;
    }
}
