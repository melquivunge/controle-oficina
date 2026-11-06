<?php

namespace App\Http\Controllers;

use App\Models\LinkCliente;
use App\Models\Orcamento;

/** Área do cliente (só visualização; a aprovação é interna desde 06/11/2026): acesso só pelo token; escopo restrito à ordem do link. */
class PortalController extends Controller
{
    public function show(string $token)
    {
        $link = $this->link($token);
        $link->forceFill(['ultimo_acesso_em' => now()])->save();
        $ordem = $link->ordem()->with('unidade', 'veiculo', 'cliente')->first();

        return response()->view('portal.show', [
            'token' => $token,
            'ordem' => $ordem,
            'pendente' => Orcamento::with('itens')->where('ordem_id', $ordem->id)->where('status', 'enviado')->first(),
            'aprovado' => Orcamento::with('itens')->where('ordem_id', $ordem->id)->where('status', 'aprovado')->first(),
            'cobranca' => \App\Models\Cobranca::where('ordem_id', $ordem->id)->where('status', 'pendente')->first(),
            'documentos' => \App\Models\FiscalDocument::where('ordem_id', $ordem->id)->whereIn('status', ['autorizado', 'cancelado'])->get(),
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function documento(string $token, int $documento)
    {
        $link = $this->link($token);
        $doc = \App\Models\FiscalDocument::where('ordem_id', $link->ordem_id)->whereNotNull('arquivo')->findOrFail($documento);

        return \App\Support\DocumentoFiscalHtml::resposta($doc, 'no-store');
    }

    /** Token inválido, expirado ou revogado: mesma resposta 404 (sem enumeração). */
    private function link(string $token): LinkCliente
    {
        $link = strlen($token) === 48 ? LinkCliente::where('token_hash', LinkCliente::hash($token))->first() : null;
        abort_unless($link?->valido(), 404);

        return $link;
    }
}
