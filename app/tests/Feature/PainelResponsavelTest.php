<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Tests\TestCase;

/** Filtro "Responsável (entrada)" do painel: métricas e CSV só das ordens registradas pelo usuário escolhido. */
class PainelResponsavelTest extends TestCase
{
    private Unidade $a;
    private Unidade $b;
    private User $rec1;
    private User $rec2;
    private User $gerA;
    private User $recB;
    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Unidade::factory()->create(['nome' => 'Oficina A']);
        $this->b = Unidade::factory()->create(['nome' => 'Oficina B']);
        $this->rec1 = User::factory()->papel(Papel::Recepcao, $this->a)->create(['name' => 'Ana Recepção']);
        $this->rec2 = User::factory()->papel(Papel::Recepcao, $this->a)->create(['name' => 'Bruno Recepção']);
        $this->gerA = User::factory()->papel(Papel::Gerente, $this->a)->create(['name' => 'Gerente A']);
        $this->recB = User::factory()->papel(Papel::Recepcao, $this->b)->create(['name' => 'Carla Unidade B']);
        $this->dono = User::factory()->proprietario()->create();
        $mec = $this->usuario(Papel::Mecanico, $this->a);

        // rec1: 2 ordens (R$ 100 recebido cada); rec2: 1 ordem (R$ 300 recebido).
        foreach ([[$this->rec1, 'AAA1A11', 10000], [$this->rec1, 'AAA1A12', 10000], [$this->rec2, 'BBB2B22', 30000]] as $i => [$rec, $placa, $valor]) {
            $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $this->a->id, 'nome' => 'Cliente '.$i, 'telefone' => '6199123450'.$i, 'placa' => $placa, 'motivo' => 'x']);
            app(Ordens::class)->iniciarDiagnostico($o, $mec);
            $s = app(Orcamentos::class);
            $v = $s->salvarRascunho($o, $this->gerA, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => (string) ($valor / 100)]]);
            $s->enviar($v, $this->gerA);
            $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
            app(Financeiro::class)->registrarManual($o->fresh(), $this->gerA, 'dinheiro', $valor); // quem recebe não importa
        }
    }

    public function test_filtro_altera_metricas_series_e_lista(): void
    {
        $todos = $this->entrarComo($this->gerA)->get('/?periodo=7')->assertOk();
        $this->assertSame(3, $todos->viewData('total')['entradas']);
        $this->assertSame(50000, $todos->viewData('total')['recebido']);

        $r = $this->entrarComo($this->gerA)->get("/?periodo=7&responsavel={$this->rec1->id}")->assertOk()
            ->assertSee('Responsável (entrada)')->assertSee('Entradas registradas por Ana Recepção')
            ->assertSee('registrada por Ana Recepção (não quem recebeu')
            ->assertSee('responsavel='.$this->rec1->id, false); // preservado nos links de período
        $t = $r->viewData('total');
        $this->assertSame(2, $t['entradas']);
        $this->assertSame(20000, $t['aprovado']);
        $this->assertSame(20000, $t['recebido']);
        $this->assertSame(20000, $t['orcado']);
        $this->assertSame(2, array_sum($r->viewData('linhas')[$this->a->id]['por_etapa'])); // somar() não agrega por_etapa
        $this->assertSame(2, array_sum($r->viewData('serie')['entradas']));
        $this->assertSame(20000, array_sum($r->viewData('serie')['recebido']));
        $this->assertSame(['dinheiro' => 20000], $r->viewData('serie')['por_meio']);
        $this->assertCount(2, $r->viewData('ordens'));

        $t2 = $this->entrarComo($this->gerA)->get("/?periodo=7&responsavel={$this->rec2->id}")->assertOk()->viewData('total');
        $this->assertSame(1, $t2['entradas']);
        $this->assertSame(30000, $t2['recebido']);
    }

    public function test_csv_respeita_filtro(): void
    {
        $csv = $this->entrarComo($this->gerA)->get("/painel/exportar?periodo=7&responsavel={$this->rec1->id}")->assertOk()->streamedContent();
        $this->assertStringContainsString('responsavel_entrada', $csv);
        $this->assertStringContainsString('Ana Recepção', $csv);
        $this->assertStringContainsString(';200,00;', $csv, 'aprovado/recebido só das ordens da Ana');
        $this->assertStringNotContainsString('500,00', $csv);

        $semFiltro = $this->entrarComo($this->gerA)->get('/painel/exportar?periodo=7')->assertOk()->streamedContent();
        $this->assertStringContainsString('500,00', $semFiltro);

        $this->entrarComo($this->gerA)->get("/painel/exportar?periodo=7&responsavel={$this->recB->id}")->assertForbidden();
    }

    public function test_opcoes_e_validacao_por_unidade_no_servidor(): void
    {
        $ops = $this->entrarComo($this->gerA)->get('/')->assertOk()->viewData('responsaveis')->pluck('id')->all();
        $this->assertContains($this->rec1->id, $ops);
        $this->assertContains($this->gerA->id, $ops);
        $this->assertNotContains($this->recB->id, $ops, 'gerente não vê usuários de outra unidade');
        $this->entrarComo($this->gerA)->get('/')->assertDontSee('Carla Unidade B');

        $this->entrarComo($this->gerA)->get("/?responsavel={$this->recB->id}")->assertForbidden();
        $this->entrarComo($this->gerA)->get('/?responsavel=999999')->assertForbidden();
        $this->entrarComo($this->gerA)->get('/?responsavel=abc')->assertSessionHasErrors('responsavel');

        // Mecânico e usuários inativos não são opções; proprietário vê todas as unidades.
        $inativo = User::factory()->papel(Papel::Recepcao, $this->a)->create(['ativo' => false]);
        $donoOps = $this->entrarComo($this->dono)->get('/')->assertOk()->viewData('responsaveis')->pluck('id')->all();
        $this->assertContains($this->recB->id, $donoOps);
        $this->assertContains($this->rec1->id, $donoOps);
        $this->assertNotContains($inativo->id, $donoOps);
        $this->assertSame(0, User::whereIn('id', $donoOps)->where('papel', Papel::Mecanico->value)->count());
        $this->entrarComo($this->dono)->get("/?responsavel={$this->recB->id}")->assertOk();
    }
}
