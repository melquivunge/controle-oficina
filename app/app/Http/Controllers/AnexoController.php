<?php

namespace App\Http\Controllers;

use App\Models\Anexo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnexoController extends Controller
{
    /** Download autorizado pela ordem dona do arquivo; nunca por caminho vindo da requisição. */
    public function __invoke(Request $request, Anexo $anexo)
    {
        $this->authorize('view', $anexo->ordem);

        return Storage::disk('local')->response($anexo->caminho, $anexo->nome_original, [
            'Content-Type' => $anexo->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
