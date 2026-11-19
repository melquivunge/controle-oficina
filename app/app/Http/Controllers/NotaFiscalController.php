<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocument;
use App\Support\DadosFiscais;
use App\Support\Fuso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Todas as notas fiscais (OS e avulsas) para controle e para o contador (19/11/2026). Rotas com can:cobrar; unidades do usuário. */
class NotaFiscalController extends Controller
{
    public const SITUACOES = ['emitidas' => 'Autorizadas e canceladas', 'autorizado' => 'Autorizadas', 'cancelado' => 'Canceladas',
        'rejeitado' => 'Rejeitadas', 'pendente' => 'Em emissão', 'todas' => 'Todas'];

    public function index(Request $request)
    {
        $f = $this->filtros($request);

        return view('notas-fiscais.index', ['f' => $f, 'notas' => $this->consulta($request, $f)->paginate(50)->withQueryString()]);
    }

    /** Planilha para o contador (separador ;). */
    public function csv(Request $request)
    {
        $notas = $this->consulta($request, $this->filtros($request))->get();

        return response()->streamDownload(function () use ($notas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['tipo', 'serie', 'numero', 'situacao', 'data_emissao', 'data_cancelamento', 'cliente', 'cpf_cnpj', 'valor', 'origem', 'chave_acesso', 'protocolo', 'motivo'], ';');
            foreach ($notas as $d) {
                $c = $d->ordem?->cliente ?? $d->notaAvulsa?->cliente;
                fputcsv($out, [DadosFiscais::NOTAS[$d->tipo] ?? $d->tipo, $d->serie, $d->numero_dps ?? $d->numero, $d->rotuloStatus(), Fuso::exibir($d->autorizado_em ?? $d->created_at),
                    $d->cancelado_em ? Fuso::exibir($d->cancelado_em) : '', $c?->nome, $c?->documento, number_format($d->valor_centavos / 100, 2, ',', ''),
                    $d->ordem ? 'OS '.$d->ordem->numero : ($d->notaAvulsa ? 'Nota avulsa '.$d->notaAvulsa->referencia() : ''), $d->chave_acesso, $d->protocolo,
                    $d->status === 'cancelado' ? $d->cancelamento_motivo : ($d->status === 'rejeitado' ? $d->mensagem : '')], ';');
            }
            fclose($out);
        }, 'notas-fiscais.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** XML das notas filtradas num ZIP (o que o contador pede todo mês). */
    public function xmls(Request $request)
    {
        $notas = $this->consulta($request, $this->filtros($request))->whereNotNull('xml')->get();
        abort_if($notas->isEmpty(), 404, 'Nenhum XML no filtro escolhido.');
        $tmp = tempnam(sys_get_temp_dir(), 'xmls');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($notas as $d) {
            if (Storage::disk('local')->exists($d->xml)) {
                $zip->addFromString(($d->tipo === 'nfe' ? 'NFe' : 'NFSe').'-'.$d->serie.'-'.str_pad((string) ($d->numero_dps ?? $d->id), 6, '0', STR_PAD_LEFT)
                    .($d->status === 'cancelado' ? '-cancelada' : '').'.xml', Storage::disk('local')->get($d->xml));
            }
        }
        $zip->close();

        return response()->download($tmp, 'xmls-notas-fiscais.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    private function filtros(Request $request): array
    {
        return $request->validate([
            'tipo' => ['nullable', Rule::in(array_keys(DadosFiscais::NOTAS))],
            'situacao' => ['nullable', Rule::in(array_keys(self::SITUACOES))],
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
            'q' => ['nullable', 'string', 'max:60'],
        ]) + ['situacao' => 'emitidas'];
    }

    private function consulta(Request $request, array $f): Builder
    {
        $user = $request->user();
        $situacao = $f['situacao'] ?? 'emitidas';
        $q = trim((string) ($f['q'] ?? ''));

        return FiscalDocument::query()->with('ordem.cliente', 'notaAvulsa.cliente')
            ->when(! $user->visaoGlobal(), fn ($b) => $b->whereIn('unidade_id', $user->unidadeIds()))
            ->when($f['tipo'] ?? null, fn ($b, $t) => $b->where('tipo', $t))
            ->when($situacao === 'emitidas', fn ($b) => $b->whereIn('status', ['autorizado', 'cancelado']))
            ->when($situacao === 'pendente', fn ($b) => $b->whereIn('status', ['pendente', 'processando']))
            ->when(in_array($situacao, ['autorizado', 'cancelado', 'rejeitado'], true), fn ($b) => $b->where('status', $situacao))
            ->when($f['de'] ?? null, fn ($b, $d) => $b->where('created_at', '>=', Fuso::inicioDoDia($d)))
            ->when($f['ate'] ?? null, fn ($b, $d) => $b->where('created_at', '<', Fuso::inicioDoDia($d)->addDay()))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('numero', $q)->orWhere('numero_dps', ctype_digit($q) ? (int) $q : -1)->orWhere('chave_acesso', $q)
                ->orWhereHas('ordem', fn ($o) => $o->where('numero', 'ilike', '%'.addcslashes($q, '%_\\').'%')->orWhereHas('cliente', fn ($c) => $c->buscar($q)))
                ->orWhereHas('notaAvulsa.cliente', fn ($c) => $c->buscar($q))))
            ->orderByDesc('id');
    }
}
