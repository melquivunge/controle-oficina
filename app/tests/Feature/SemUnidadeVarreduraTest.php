<?php

namespace Tests\Feature;

use App\Enums\Papel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Varredura: todas as telas GET sem parâmetro, com a proprietária e ainda sem nenhuma unidade (primeiro acesso). */
class SemUnidadeVarreduraTest extends TestCase
{
    public function test_nenhuma_tela_quebra_ou_nega_acesso_por_falta_de_unidade(): void
    {
        $dona = $this->usuario(Papel::Proprietario);
        $falhas = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            if (! in_array('GET', $r->methods(), true) || str_contains($uri, '{') || ! in_array('auth', $r->gatherMiddleware(), true)
                || in_array($uri, ['sair', 'dois-fatores', 'confirmar-senha'], true)) {
                continue;
            }
            $s = $this->entrarComo($dona)->get("/".ltrim($uri, "/"))->baseResponse->getStatusCode();
            if ($s === 403 || $s >= 500) {
                $falhas[] = "$s /$uri";
            }
        }
        $this->assertSame([], $falhas);

        // As telas por unidade explicam o que falta e levam ao cadastro.
        foreach (['/caixa', '/conferencia', '/feriados', '/entradas/nova'] as $url) {
            $this->entrarComo($dona)->get($url)->assertOk()->assertSee('Nenhuma unidade cadastrada')->assertSee('Cadastrar unidade');
        }
    }
}
