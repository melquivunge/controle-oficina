<?php

namespace App\Http\Controllers;

use App\Support\Normaliza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OS do sistema antigo (09/11/2026): consulta só leitura do arquivo importado (legado_registros).
 * Separada de "Ordens de serviço" de propósito: não entra em painel, caixa, numeração nem relatórios.
 * Só aparece no menu quando a empresa importou um sistema antigo.
 */
class HistoricoAntigoController extends Controller
{
    /** Há OS antigas importadas? (uma consulta por requisição) */
    public static function existe(): bool
    {
        return once(fn () => DB::table('legado_registros')->where('tabela', 'TBL_OS')->exists());
    }

    public static function total(): int
    {
        return once(fn () => DB::table('legado_registros')->where('tabela', 'TBL_OS')->count());
    }

    public function index(Request $request)
    {
        abort_unless(self::existe(), 404);
        $f = $request->validate(['busca' => ['nullable', 'string', 'max:100'], 'situacao' => ['nullable', 'in:aberta,finalizada,cancelada'],
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);
        $busca = trim((string) ($f['busca'] ?? ''));
        $q = DB::table('legado_registros as r')->leftJoin('clientes as c', 'c.id', '=', 'r.cliente_id')->leftJoin('veiculos as v', 'v.id', '=', 'r.veiculo_id')
            ->where('r.tabela', 'TBL_OS')
            ->when($busca !== '', function ($q) use ($busca) {
                $digitos = preg_replace('/\D/', '', $busca);
                $placa = Normaliza::placa($busca);
                $q->where(function ($q) use ($busca, $digitos, $placa) {
                    // Mesma busca tolerante a erro da Nova entrada (sem acento, fora de ordem, letra trocada).
                    $q->whereIn('c.id', \App\Models\Cliente::query()->parecido($busca)->reorder()->select('clientes.id'));
                    if ($digitos !== '' && $digitos === $busca) {
                        $q->orWhere('r.codigo', $digitos);
                    }
                    if (strlen($digitos) >= 4) {
                        $q->orWhere('c.telefone', 'like', '%'.$digitos.'%');
                    }
                    if (strlen($placa) >= 3 && preg_match('/\d/', $placa)) {
                        $q->orWhere('v.placa', 'like', '%'.$placa.'%');
                    }
                });
            })
            ->when($f['situacao'] ?? null, fn ($q, $s) => match ($s) {
                'cancelada' => $q->whereRaw("r.dados->>'CANCELADA' = 'S'"),
                'finalizada' => $q->whereRaw("coalesce(r.dados->>'CANCELADA','N') <> 'S' and r.dados->>'STATUS' = 'F'"),
                'aberta' => $q->whereRaw("coalesce(r.dados->>'CANCELADA','N') <> 'S' and coalesce(r.dados->>'STATUS','A') <> 'F'"),
            })
            ->when($f['de'] ?? null, fn ($q, $d) => $q->whereRaw("r.dados->>'DATAIN' >= ?", [$d]))
            ->when($f['ate'] ?? null, fn ($q, $d) => $q->whereRaw("r.dados->>'DATAIN' <= ?", [$d]))
            ->orderByRaw("r.dados->>'DATAIN' desc nulls last, length(r.codigo) desc, r.codigo desc")
            ->select('r.id', 'r.codigo', 'r.dados', 'c.id as cliente_id', 'c.nome', 'v.placa', 'v.marca', 'v.modelo');

        return view('historico.index', ['lista' => $q->paginate(30)->withQueryString(), 'total' => self::total(), 'f' => $f]);
    }

    /** Versão para imprimir, em linguagem simples (09/11/2026). */
    public function imprimir(int $id)
    {
        return view('historico.imprimir', $this->show($id)->getData() + ['id' => $id]);
    }

    public function show(int $id)
    {
        $os = DB::table('legado_registros')->where('id', $id)->where('tabela', 'TBL_OS')->firstOrFail();
        $relacionados = DB::table('legado_registros')->where('grupo', $os->grupo)->where('id', '!=', $os->id)->orderBy('tabela')->orderBy('linha')->get();

        return view('historico.show', [
            'os' => json_decode($os->dados, true),
            'cliente' => $os->cliente_id ? \App\Models\Cliente::find($os->cliente_id) : null,
            'veiculo' => $os->veiculo_id ? \App\Models\Veiculo::find($os->veiculo_id) : null,
            'itens' => $relacionados->whereIn('tabela', ['TBL_SERVICO', 'TBL_PECA'])->map(fn ($i) => ['tipo' => $i->tabela === 'TBL_PECA' ? 'Peça' : 'Serviço'] + json_decode($i->dados, true))->values(),
            'outros' => $relacionados->whereNotIn('tabela', ['TBL_SERVICO', 'TBL_PECA'])->groupBy('tabela')->map(fn ($g) => $g->map(fn ($i) => json_decode($i->dados, true))),
        ]);
    }
}
