<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\AuditEvent;
use App\Models\Unidade;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Tests\TestCase;

/** Painel simples (02/11/2026): liga/desliga por usuário, só gerência/proprietário, mesmo escopo de unidade. */
class PainelSimplesTest extends TestCase
{
    public function test_gerente_liga_e_desliga_e_ve_so_a_propria_loja(): void
    {
        $a = Unidade::factory()->create(['nome' => 'Loja Asa Sul']);
        $b = Unidade::factory()->create(['nome' => 'Loja Taguatinga']);
        $ger = $this->usuario(Papel::Gerente, $a);
        $rec = $this->usuario(Papel::Recepcao, $a);
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $a->id, 'nome' => 'Mariana', 'telefone' => '61991234567', 'placa' => 'QWE4R56', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->usuario(Papel::Mecanico, $a));
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $ger, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '250']]);
        $s->enviar($v, $ger);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
        app(Financeiro::class)->registrarManual($o->fresh(), $rec, 'dinheiro', 10000);
        app(Entradas::class)->registrar($this->usuario(Papel::Recepcao, $b), ['unidade_id' => $b->id, 'nome' => 'Outro', 'telefone' => '61991234568', 'placa' => 'ABC1D23', 'motivo' => 'x']);

        // Desligado por padrão: painel completo com o botão para ligar.
        $this->entrarComo($ger)->get('/')->assertOk()->assertSee('Versão simples')->assertSee('Recebido por forma');

        $this->entrarComo($ger)->put('/painel/modo', ['simples' => 1])->assertRedirect(route('painel'));
        $this->assertTrue($ger->fresh()->painel_simples);
        $this->assertTrue(AuditEvent::where('acao', 'painel_modo_alterado')->where('ator_id', $ger->id)->exists());

        $this->entrarComo($ger->fresh())->get('/')->assertOk()
            ->assertSee('O que precisa ser feito')->assertSee('Quanto entrou de dinheiro hoje?')
            ->assertSee('R$ 100,00')->assertSee('R$ 150,00') // recebido hoje e falta receber (250 - 100)
            ->assertSee('Loja Asa Sul')->assertDontSee('Loja Taguatinga')
            ->assertSee('Painel completo')->assertDontSee('Recebido por forma');
        $this->entrarComo($ger)->get("/?unidade={$b->id}")->assertForbidden();

        $this->entrarComo($ger->fresh())->put('/painel/modo', ['simples' => 0])->assertRedirect(route('painel'));
        $this->entrarComo($ger->fresh())->get('/')->assertOk()->assertSee('Recebido por forma')->assertDontSee('O que precisa ser feito');
    }

    public function test_proprietario_ve_todas_as_lojas_e_tudo_em_dia_sem_pendencias(): void
    {
        Unidade::factory()->create(['nome' => 'Loja Um']);
        Unidade::factory()->create(['nome' => 'Loja Dois']);
        $dono = $this->usuario(Papel::Proprietario);
        $dono->forceFill(['painel_simples' => true])->save();

        $this->entrarComo($dono)->get('/')->assertOk()->assertSee('Loja Um')->assertSee('Loja Dois')
            ->assertSee('Tudo em dia')->assertSee('2 lojas');
    }

    public function test_recepcao_nao_liga_nem_recebe_versao_simples(): void
    {
        $a = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $a);
        $this->entrarComo($rec)->put('/painel/modo', ['simples' => 1])->assertForbidden();
        $this->assertFalse((bool) $rec->fresh()->painel_simples);
        $this->entrarComo($rec)->get('/')->assertOk()->assertDontSee('Versão simples');

        // Mesmo com a flag gravada por fora, o servidor não troca a visão de quem não vê indicadores.
        $rec->forceFill(['painel_simples' => true])->save();
        $this->entrarComo($rec->fresh())->get('/')->assertOk()->assertDontSee('O que precisa ser feito')->assertSee('No pátio agora');
    }

    public function test_valor_invalido_e_rejeitado(): void
    {
        $ger = $this->usuario(Papel::Gerente, Unidade::factory()->create());
        $this->entrarComo($ger)->put('/painel/modo', ['simples' => 'talvez'])->assertSessionHasErrors('simples');
        $this->assertFalse((bool) $ger->fresh()->painel_simples);
    }
}
