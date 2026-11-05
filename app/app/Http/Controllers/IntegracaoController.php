<?php

namespace App\Http\Controllers;

/** Página informativa: modo das integrações lido da configuração. Nunca exibe segredos. */
class IntegracaoController extends Controller
{
    public function __invoke()
    {
        return view('integracoes.index', [
            'simulado' => config('oficina.integracoes') === 'simulado',
            'permiteSimularPix' => (bool) config('oficina.pix.permite_simular_pagamento'),
            'segredoPixDefinido' => filled(config('oficina.pix.webhook_segredo')), // só presença, nunca o valor
            'validadePix' => (int) config('oficina.pix.validade_minutos'),
            'fiscalIndisponivel' => (bool) config('oficina.fiscal.simular_indisponivel'),
            'tentativasMensagem' => (new \App\Jobs\EnviarMensagem(0))->tries,
        ]);
    }
}
