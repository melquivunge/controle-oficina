<?php

namespace Tests\Feature;

use App\Enums\Papel;
use Tests\TestCase;

/** Primeiro acesso em produção (05/11/2026): proprietária recém-criada, ainda sem nenhuma unidade cadastrada. */
class PrimeiroAcessoTest extends TestCase
{
    public function test_proprietaria_sem_unidades_ve_o_painel_e_o_caminho_para_cadastrar(): void
    {
        $dona = $this->usuario(Papel::Proprietario);

        $this->entrarComo($dona)->get('/')->assertOk()->assertSee('Nenhuma unidade ativa')->assertSee('R$ 0,00');
        foreach (['/indicadores', '/painel/exportar', '/configuracoes/unidades'] as $url) {
            $this->entrarComo($dona)->get($url)->assertOk();
        }
        // A linha zerada tem exatamente os campos que o cálculo por unidade devolve.
        $u = \App\Models\Unidade::factory()->create();
        $linha = app(\App\Services\Indicadores::class)->porUnidade([$u->id], now()->toImmutable()->subDay(), now()->toImmutable())[$u->id];
        $this->assertSame(array_keys($linha), array_keys(\App\Services\Indicadores::zerada()));
        $u->delete();

        $dona->forceFill(['painel_simples' => true])->save();
        $this->entrarComo($dona->fresh())->get('/')->assertOk()->assertSee('Nenhuma unidade ativa');
    }
}
