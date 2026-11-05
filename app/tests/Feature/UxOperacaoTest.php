<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Divergencia;
use App\Models\Unidade;
use App\Services\Entradas;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Tests\TestCase;

/** Reorganização mobile da avaliação de 30/10: abas, conferência marcada, caixa a receber, busca por placa. */
class UxOperacaoTest extends TestCase
{
    public function test_conferencia_por_marcacao_presente_ausente(): void
    {
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $this->usuario(Papel::Gerente, $u);
        $e = app(Entradas::class);
        $o1 = $e->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'A', 'telefone' => '61991234567', 'placa' => 'AAA1111', 'motivo' => 'x']);
        $o2 = $e->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'B', 'telefone' => '61991234568', 'placa' => 'BBB2222', 'motivo' => 'x']);

        $this->entrarComo($rec)->get("/conferencia?unidade={$u->id}")->assertOk()
            ->assertSee("marcacao[{$o1->id}]", false)->assertSee('0 de 2 conferidos');
        $this->entrarComo($rec)->post('/conferencia', ['unidade' => $u->id, 'marcacao' => [$o1->id => 'presente', $o2->id => 'ausente']])->assertRedirect();
        $this->assertSame([['ordem_sem_veiculo', $o2->id]], Divergencia::get()->map(fn ($d) => [$d->tipo, $d->ordem_id])->all());

        $this->entrarComo($rec)->post('/conferencia', ['unidade' => $u->id, 'marcacao' => [$o1->id => 'talvez']])->assertSessionHasErrors('marcacao.'.$o1->id);
    }

    public function test_ordem_em_abas_caixa_a_receber_e_busca_por_placa(): void
    {
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $ger = $this->usuario(Papel::Gerente, $u);
        $mec = $this->usuario(Papel::Mecanico, $u);
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'Mariana', 'telefone' => '61991234567', 'placa' => 'QWE4R56', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $mec);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $ger, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '250']]);
        $s->enviar($v, $ger);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');

        $html = $this->entrarComo($rec)->get("/ordens/{$o->numero}?aba=pagamentos")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="aba-painel" >\s*<section class="form-panel"[^>]*id="pagamento"/', $html, 'aba escolhida visível');
        $this->assertStringContainsString('Abrir orçamento e execução', $this->entrarComo($rec)->get("/ordens/{$o->numero}")->getContent(), 'resumo indica a próxima ação');
        $this->entrarComo($mec)->get("/ordens/{$o->numero}")->assertOk()->assertDontSee('Documentos e mensagens');

        $this->entrarComo($ger)->get("/caixa?unidade={$u->id}&aba=receber")->assertOk()->assertSee('A receber (1)')->assertSee('R$ 250,00');
        $this->entrarComo($rec)->get('/ordens?busca=qwe-4r56')->assertOk()->assertSee($o->numero);
        $this->entrarComo($rec)->get('/ordens?busca=ZZZ9Z99')->assertOk()->assertDontSee($o->numero);
    }
}
