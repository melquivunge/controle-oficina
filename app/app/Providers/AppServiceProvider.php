<?php

namespace App\Providers;

use App\Enums\Papel;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Integracoes\Pix\ProvedorPix::class, fn () => config('oficina.integracoes') === 'simulado'
            ? new \App\Integracoes\Pix\SimuladorPix
            : new \App\Integracoes\Pix\ProvedorIndisponivel);
        $this->app->bind(\App\Integracoes\Mensagens\Mensageiro::class, fn () => config('oficina.integracoes') === 'simulado'
            ? new \App\Integracoes\Mensagens\SimuladorMensagens
            : new \App\Integracoes\Mensagens\MensageiroIndisponivel);
        $this->app->bind(\App\Integracoes\Fiscal\EmissorFiscal::class, fn () => config('oficina.integracoes') === 'simulado'
            ? new \App\Integracoes\Fiscal\SimuladorFiscal
            : new \App\Integracoes\Fiscal\EmissorIndisponivel);
        $this->app->bind(\App\Integracoes\Placa\ConsultaPlaca::class, fn () => match (true) {
            config('oficina.placa.provedor') === 'integrasist' => new \App\Integracoes\Placa\IntegrasistPlaca, // só leitura; ligado explicitamente
            config('oficina.integracoes') === 'simulado' || config('database.default') === 'demo' => new \App\Integracoes\Placa\SimuladorPlaca,
            default => new \App\Integracoes\Placa\ConsultaPlacaIndisponivel,
        });
    }

    public function boot(): void
    {
        Paginator::defaultView('partials.paginacao');

        // Vendedor da OS (31/10/2026): definido ao enviar/aprovar o orçamento, sem tocar no serviço de orçamentos.
        \App\Models\Orcamento::updated(function (\App\Models\Orcamento $o) {
            if ($o->wasChanged('status') && in_array($o->status, ['enviado', 'aprovado'], true)) {
                app(\App\Services\Vendedores::class)->definirPadrao($o);
            }
        });
        Paginator::defaultSimpleView('partials.paginacao');

        $permissoes = collect(Papel::cases())->flatMap(fn (Papel $p) => $p->permissoes())->unique();
        foreach ($permissoes as $permissao) {
            Gate::define($permissao, fn (User $user) => $user->pode($permissao));
        }

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('portal', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('dois-fatores', fn (Request $request) => [
            Limit::perMinute(5)->by('m'.($request->user()?->id ?: $request->ip())),
            Limit::perHour(15)->by('h'.($request->user()?->id ?: $request->ip())),
        ]);
    }
}
