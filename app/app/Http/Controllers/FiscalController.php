<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocument;
use App\Models\MessageDelivery;
use App\Models\Ordem;
use App\Services\Fiscal;
use App\Services\Mensagens;
use App\Support\DadosFiscais;
use App\Support\DocumentoFiscalHtml;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class FiscalController extends Controller
{
    public function solicitar(Request $request, Ordem $ordem, Fiscal $fiscal)
    {
        $notas = $fiscal->solicitar($ordem, $request->user());

        return back()->with('sucesso', $notas->map(fn ($d) => DadosFiscais::NOTAS[$d->tipo])->join(' e ').' solicitada'.($notas->count() > 1 ? 's' : '').'. O resultado aparece aqui em instantes.');
    }

    public function reprocessar(Request $request, Ordem $ordem, FiscalDocument $documento, Fiscal $fiscal)
    {
        abort_unless($documento->ordem_id === $ordem->id, 404);
        $fiscal->reprocessar($documento, $request->user());

        return back()->with('sucesso', 'Nova tentativa de emissão enviada.');
    }

    public function interromper(Request $request, Ordem $ordem, FiscalDocument $documento, Fiscal $fiscal)
    {
        abort_unless($documento->ordem_id === $ordem->id, 404);

        return back()->with('sucesso', $fiscal->interromper($documento, $request->user()) ? 'Emissão interrompida. O sistema parou de tentar.' : 'A Fazenda já tinha esta nota: ela foi registrada como autorizada.');
    }

    public function cancelar(Request $request, Ordem $ordem, FiscalDocument $documento, Fiscal $fiscal)
    {
        abort_unless($documento->ordem_id === $ordem->id, 404);
        // Bolsa de erros própria: o campo "motivo" também existe nos formulários de cancelar/entregar a ordem.
        // Na falha, volta para o documento com o formulário aberto e um alerta visível (antes o erro ficava
        // escondido dentro do <details> fechado e o cancelamento parecia não fazer nada).
        $v = Validator::make($request->only('motivo'), ['motivo' => ['required', 'string', 'min:15', 'max:255']], [], ['motivo' => 'justificativa']);
        if ($v->fails()) {
            return redirect()->to($this->voltar($documento))
                ->withErrors($v, 'fiscal_cancelar')
                ->withInput(['motivo_fiscal' => (string) $request->input('motivo')])
                ->with('fiscal_cancelar', $documento->id);
        }
        $fiscal->cancelar($documento, $request->user(), $v->validated()['motivo']);

        return redirect()->to($this->voltar($documento))->with('sucesso', "Documento fiscal {$documento->numero} cancelado.");
    }

    /** Estado leve da seção fiscal para acompanhar a emissão assíncrona sem recarregar a página. */
    public function status(Request $request, Ordem $ordem)
    {
        $this->authorize('view', $ordem);
        abort_unless($request->user()->can('ver_valores'), 403);
        $docs = FiscalDocument::where('ordem_id', $ordem->id)->orderByDesc('id')->get();

        return response()->json([
            'acompanhar' => $docs->whereIn('status', ['pendente', 'processando'])->isNotEmpty(),
            'assinatura' => $docs->map(fn ($d) => $d->id.':'.$d->status.':'.$d->updated_at?->timestamp)->join('|'),
            'html' => view('ordens._fiscal', ['ordem' => $ordem, 'docs' => $docs])->render(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function baixar(Request $request, Ordem $ordem, FiscalDocument $documento)
    {
        abort_unless($documento->ordem_id === $ordem->id && ($documento->arquivo || $documento->xml), 404);
        $this->authorize('view', $ordem);
        abort_unless($request->user()->pode('cobrar'), 403);
        if ($request->boolean('xml') || ! $documento->arquivo) { // XML autorizado (etapa 2), para o contador
            abort_unless($documento->xml, 404);

            return response(Storage::disk('local')->get($documento->xml), 200, ['Content-Type' => 'application/xml',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'attachment; filename="'.($documento->chave_acesso ?: 'nota-'.$documento->id).'.xml"']);
        }

        return DocumentoFiscalHtml::resposta($documento, 'private, no-store');
    }

    private function voltar(FiscalDocument $documento): string
    {
        return strtok(url()->previous(), '#').'#fiscal-'.$documento->id;
    }

    public function reenviarMensagem(Request $request, Ordem $ordem, MessageDelivery $mensagem, Mensagens $mensagens)
    {
        abort_unless($mensagem->ordem_id === $ordem->id && $mensagem->status === 'falhou', 404);
        $mensagens->reenviar($mensagem, $request->user());

        return back()->with('sucesso', 'Mensagem enviada novamente para a fila.');
    }
}
