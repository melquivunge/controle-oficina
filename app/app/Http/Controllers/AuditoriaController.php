<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Unidade;
use App\Services\Auditoria;
use App\Support\Acoes;
use App\Support\Fuso;
use Illuminate\Http\Request;

class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('auditoria.index', [
            'eventos' => $this->consulta($request)->with('ator', 'unidade')->orderByDesc('id')->paginate(50)->withQueryString(),
            'unidades' => Unidade::visiveisPara($user)->orderBy('nome')->get(),
            'acoes' => AuditEvent::visiveisPara($user)->distinct()->orderBy('acao')->pluck('acao'),
        ]);
    }

    /** CSV com os mesmos filtros e a mesma visibilidade por unidade da tela. */
    public function exportar(Request $request)
    {
        $consulta = $this->consulta($request)->with('ator', 'unidade');
        Auditoria::registrar('auditoria_exportada', depois: ['filtros' => $request->only('unidade', 'acao', 'de', 'ate')], ator: $request->user());

        return response()->streamDownload(function () use ($consulta) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF"); // BOM: acentos corretos no Excel
            fputcsv($saida, ['Quando', 'Quem', 'Unidade', 'Ação', 'Registro', 'Motivo', 'Antes', 'Depois'], ';');
            foreach ($consulta->lazyByIdDesc(500) as $e) {
                fputcsv($saida, array_map([self::class, 'celula'], [
                    Fuso::exibir($e->ocorrido_em, 'd/m/Y H:i:s'), $e->ator?->name ?? ucfirst($e->ator_tipo), $e->unidade?->nome ?? '',
                    Acoes::rotulo($e->acao), $e->entidade_tipo ? $e->entidade_tipo.' #'.$e->entidade_id : '', (string) $e->motivo,
                    $e->antes ? json_encode($e->antes, JSON_UNESCAPED_UNICODE) : '', $e->depois ? json_encode($e->depois, JSON_UNESCAPED_UNICODE) : '',
                ]), ';');
            }
            fclose($saida);
        }, 'auditoria-'.Fuso::exibir(now(), 'Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    /** Neutraliza fórmulas (CSV injection): célula iniciada por = + - @ tab ou CR vira texto. */
    public static function celula(string $valor): string
    {
        return $valor !== '' && in_array($valor[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$valor : $valor;
    }

    private function consulta(Request $request)
    {
        $user = $request->user();
        $filtros = $request->validate([
            'unidade' => ['nullable', 'integer'],
            'acao' => ['nullable', 'string', 'max:80'],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
        ]);
        if (! empty($filtros['unidade'])) {
            abort_unless($user->acessaUnidade((int) $filtros['unidade']), 403);
        }

        return AuditEvent::visiveisPara($user)
            ->when($filtros['unidade'] ?? null, fn ($q, $u) => $q->where('unidade_id', $u))
            ->when($filtros['acao'] ?? null, fn ($q, $a) => $q->where('acao', $a))
            ->when($filtros['de'] ?? null, fn ($q, $d) => $q->where('ocorrido_em', '>=', Fuso::inicioDoDia($d)))
            ->when($filtros['ate'] ?? null, fn ($q, $d) => $q->where('ocorrido_em', '<', Fuso::inicioDoDia($d)->addDay()));
    }
}
