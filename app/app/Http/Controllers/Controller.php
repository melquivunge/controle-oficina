<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /** Tela que trabalha por unidade, aberta antes de existir alguma: explica o que falta (não é falta de permissão). */
    protected function semUnidade(string $tela)
    {
        return view('sem-unidade', ['tela' => $tela]);
    }
}
