<?php

namespace App\Http\Controllers;

use App\Models\LinkCliente;
use App\Models\Ordem;
use App\Services\Auditoria;
use App\Services\Mensagens;
use App\Services\Orcamentos;
use App\Services\OsImpressa;
use App\Services\Vendedores;
use App\Support\Normaliza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** OS impressa (equipe com ver_valores; mecânico 403), versão somente leitura do cliente (link) e troca de vendedor. */
class OsImpressaController extends Controller
{
    public function interna(Request $request, Ordem $ordem, OsImpressa $impressa)
    {
        $this->authorize('imprimir', $ordem);
        Auditoria::registrar('os_impressa', $ordem, depois: ['numero' => $ordem->numero, 'canal' => 'equipe']);

        return $this->resposta($impressa->dados($ordem, interno: true));
    }

    public function portal(string $token, OsImpressa $impressa)
    {
        $link = strlen($token) === 48 ? LinkCliente::where('token_hash', LinkCliente::hash($token))->first() : null;
        abort_unless($link?->valido(), 404);
        $ordem = $link->ordem;
        Auditoria::registrar('os_impressa', $ordem, depois: ['numero' => $ordem->numero, 'canal' => 'link_cliente'], atorTipo: 'cliente');

        return $this->resposta($impressa->dados($ordem, interno: false) + ['token' => $token])->header('X-Robots-Tag', 'noindex');
    }

    public function vendedor(Request $request, Ordem $ordem, Vendedores $vendedores)
    {
        $this->authorize('definirVendedor', $ordem);
        $dados = $request->validate(['vendedor_id' => ['required', 'integer'], 'motivo' => ['nullable', 'string', 'max:300']], [], ['vendedor_id' => 'vendedor']);
        $o = $vendedores->alterar($ordem, $request->user(), (int) $dados['vendedor_id'], $dados['motivo'] ?? null);

        return back()->with('sucesso', 'Vendedor da OS: '.$o->vendedor->name.'.');
    }

    /**
     * Enviar a OS ao cliente por WhatsApp. canal=sistema: outbox oficial (simulado em demo/teste; nada real).
     * canal=manual: gera o link do portal na hora (POST; nunca em GET) e redireciona para wa.me com o mesmo texto.
     * Autorização no servidor: ver_valores + mesma unidade (policy imprimir); mecânico 403.
     */
    public function whatsapp(Request $request, Ordem $ordem, Mensagens $mensagens, Orcamentos $orcamentos)
    {
        $this->authorize('imprimir', $ordem);
        $canal = $request->validate(['canal' => ['required', 'in:sistema,manual']])['canal'];
        $ordem->loadMissing('cliente');
        $telefone = Normaliza::telefone($ordem->cliente?->telefone);
        if (strlen($telefone) < 10) {
            throw ValidationException::withMessages(['telefone' => 'O cliente não tem telefone válido cadastrado. Atualize o cadastro antes de enviar a OS por WhatsApp.']);
        }
        $ator = $request->user();
        $resultado = DB::transaction(function () use ($ordem, $ator, $canal, $mensagens, $orcamentos, $telefone) {
            $token = $orcamentos->novoLink($ordem, $ator);
            $m = $canal === 'sistema' ? $mensagens->enfileirarDocumentoOs($ordem, $token) : null;
            Auditoria::registrar('os_enviada_whatsapp', $ordem, depois: ['numero' => $ordem->numero, 'canal' => $canal,
                'mensagem_id' => $m?->id, 'telefone_final' => substr($telefone, -4)], ator: $ator);

            return [$token, $m];
        });
        if ($canal === 'manual') {
            $numero = '55'.$telefone; // Normaliza::telefone já remove o DDI 55
            $texto = $mensagens->textoDocumentoOs($ordem, route('portal.impressao', $resultado[0]));

            return redirect()->away('https://wa.me/'.$numero.'?text='.rawurlencode($texto));
        }

        return redirect()->to(route('ordens.show', $ordem).'?aba=documentos#mensagens')
            ->with('sucesso', 'OS enviada para a fila de mensagens ao cliente. Acompanhe o status em "Documentos e mensagens".');
    }

    private function resposta(array $dados)
    {
        return response()->view('ordens.impressao', $dados)->header('Cache-Control', 'no-store, private');
    }
}
