<?php

namespace App\Support;

use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Storage;

/**
 * Entrega o HTML privado do documento fiscal (simulado) com cabeçalhos restritivos.
 * O arquivo gravado na emissão não é reescrito: se o documento foi cancelado depois, o carimbo
 * de cancelamento é acrescentado na entrega, a partir dos dados persistidos (status, data, motivo).
 */
class DocumentoFiscalHtml
{
    public const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:";

    public static function resposta(FiscalDocument $doc, string $cache)
    {
        if (str_ends_with((string) $doc->arquivo, '.pdf')) { // nota real (etapa 2): PDF oficial (DANFSe/DANFE); o cancelamento consta na Fazenda
            return response(Storage::disk('local')->get($doc->arquivo), 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => $cache,
                'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="nota-'.preg_replace('/[^A-Za-z0-9-]/', '', (string) $doc->numero).'.pdf"']);
        }
        $html = self::conteudo($doc);

        return response()->stream(function () use ($html) {
            echo $html;
        }, 200, [
            'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => $cache, 'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="documento-'.preg_replace('/[^A-Za-z0-9-]/', '', (string) $doc->numero).'.html"',
        ]);
    }

    public static function conteudo(FiscalDocument $doc): string
    {
        $html = (string) Storage::disk('local')->get($doc->arquivo);
        if ($doc->status !== 'cancelado') {
            return $html;
        }
        $carimbo = '<div class="carimbo-cancelado" role="note"><strong>CANCELADO</strong><span>em '.e(Fuso::exibir($doc->cancelado_em)).'</span>'
            .'<span>Justificativa: '.e((string) $doc->cancelamento_motivo).'</span></div>'
            .'<style>.carimbo-cancelado{position:fixed;top:42%;left:50%;transform:translate(-50%,-50%) rotate(-8deg);z-index:20;display:grid;gap:4px;justify-items:center;'
            .'max-width:70%;padding:14px 28px;border:4px double #b3261e;border-radius:6px;background:rgba(255,255,255,.92);color:#b3261e;font:600 13px/1.35 system-ui,sans-serif;text-align:center}'
            .'.carimbo-cancelado strong{font-size:40px;letter-spacing:.18em}@media print{.carimbo-cancelado{position:absolute;top:120mm}}</style>';
        $pos = strripos($html, '</body>');

        return $pos === false ? $html.$carimbo : substr($html, 0, $pos).$carimbo.substr($html, $pos);
    }
}
