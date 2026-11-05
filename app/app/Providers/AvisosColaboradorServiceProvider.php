<?php

namespace App\Providers;

use App\Models\Ordem;
use App\Services\AvisosColaborador;
use Illuminate\Support\ServiceProvider;

/** Aviso ao colaborador (opcional): reage à atribuição de mecânico e à autorização da OS sem alterar os serviços de domínio. */
class AvisosColaboradorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Ordem::updated(fn (Ordem $ordem) => app(AvisosColaborador::class)->aoAtualizarOrdem($ordem));
    }
}
