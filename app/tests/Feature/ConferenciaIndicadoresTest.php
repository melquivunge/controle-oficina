<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Divergencia;
use App\Models\Recebedor;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Conferencias;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Indicadores;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

class ConferenciaIndicadoresTest extends TestCase
{
    public function test_conferencia_detecta_ordem_sem_veiculo_e_veiculo_sem_ordem_e_gerente_resolve(): void
    {
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $ger = $this->usuario(Papel::Gerente, $u);
        $e = app(Entradas::class);
        $o1 = $e->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'A', 'telefone' => '61991234567', 'placa' => 'AAA1111', 'motivo' => 'x']);
        $o2 = $e->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'B', 'telefone' => '61991234568', 'placa' => 'BBB2222', 'motivo' => 'x']);

        $this->entrarComo($rec)->post('/conferencia', ['unidade' => $u->id, 'presentes' => [$o1->id], 'placas' => "ccc-3333\nINVALIDA"])->assertSessionHasErrors('placas');
        $this->entrarComo($rec)->post('/conferencia', ['unidade' => $u->id, 'presentes' => [$o1->id], 'placas' => 'ccc-3333'])->assertRedirect();

        $div = Divergencia::orderBy('id')->get();
        $this->assertSame([['ordem_sem_veiculo', 'BBB2222', $o2->id], ['veiculo_sem_ordem', 'CCC3333', null]], $div->map(fn ($d) => [$d->tipo, $d->placa, $d->ordem_id])->all());
        $this->assertSame($ger->id, $div[0]->revisor_id, 'responsável pela revisão é o gerente da unidade');

        $this->entrarComo($rec)->post("/conferencia/divergencias/{$div[0]->id}", ['resolucao' => 'veículo no lava-jato'])->assertForbidden();
        $this->entrarComo($this->usuario(Papel::Gerente))->post("/conferencia/divergencias/{$div[0]->id}", ['resolucao' => 'outra unidade'])->assertForbidden();
        $this->entrarComo($ger)->post("/conferencia/divergencias/{$div[0]->id}", ['resolucao' => 'Veículo estava no lava-jato, confirmado'])->assertRedirect();
        $this->assertSame($ger->id, $div[0]->fresh()->resolvido_por);
        $this->assertDatabaseHas('audit_events', ['acao' => 'divergencia_resolvida', 'ator_id' => $ger->id]);
        $this->entrarComo($this->usuario(Papel::Mecanico, $u))->get('/conferencia')->assertForbidden();
    }

    public function test_placa_digitada_que_corresponde_a_ordem_aberta_confere(): void
    {
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $this->usuario(Papel::Gerente, $u);
        app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'A', 'telefone' => '61991234567', 'placa' => 'AAA1111', 'motivo' => 'x']);
        app(Conferencias::class)->registrar($u->id, $rec, [], ['aaa-1111']);
        $this->assertSame(0, Divergencia::count());
    }

    public function test_indicadores_separam_orcado_aprovado_recebido_pendente_e_respeitam_unidade(): void
    {
        [$a, $b] = Unidade::factory()->count(2)->create();
        $dono = User::factory()->proprietario()->create();
        $rec = $this->usuario(Papel::Recepcao, $a);
        $mec = $this->usuario(Papel::Mecanico, $a);
        $ger = $this->usuario(Papel::Gerente, $a);
        Recebedor::create(['unidade_id' => $a->id, 'provedor' => 'simulado', 'identificador_conta' => 'c', 'descricao' => 'c', 'ativo' => true, 'criado_por' => $dono->id]);
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $a->id, 'nome' => 'A', 'telefone' => '61991234567', 'placa' => 'AAA1111', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $mec);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $ger, [['descricao' => 'S', 'quantidade' => '1', 'preco' => '500']], 5000, 'fidelidade');
        $s->enviar($v, $ger);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
        app(Financeiro::class)->registrarManual($o, $rec, 'dinheiro', 20000);

        $i = app(Indicadores::class)->porUnidade([$a->id, $b->id], CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay());
        $this->assertSame(['entradas' => 1, 'orcado' => 45000, 'aprovado' => 45000, 'recebido' => 20000, 'pendente' => 25000, 'descontos' => 5000, 'descontos_qtd' => 1],
            array_intersect_key($i[$a->id], array_flip(['entradas', 'orcado', 'aprovado', 'recebido', 'pendente', 'descontos', 'descontos_qtd'])));
        $this->assertSame(0, $i[$b->id]['entradas']);

        $this->entrarComo($ger)->get('/indicadores')->assertOk()->assertSee($a->nome)->assertDontSee($b->nome);
        $this->entrarComo($ger)->get("/indicadores?unidade={$b->id}")->assertForbidden();
        $this->entrarComo($dono)->get('/indicadores')->assertOk()->assertSee($a->nome)->assertSee($b->nome)->assertSee('Painel central');
        $this->entrarComo($rec)->get('/indicadores')->assertForbidden();
    }

    public function test_recepcao_nao_registra_conferencia_de_outra_unidade(): void
    {
        $rec = $this->usuario(Papel::Recepcao);
        $this->expectException(AuthorizationException::class);
        app(Conferencias::class)->registrar(Unidade::factory()->create()->id, $rec, [], []);
    }

    public function test_cancelamento_conta_no_periodo_pelo_relogio_da_aplicacao(): void
    {
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $ger = $this->usuario(Papel::Gerente, $u);
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00', 'UTC'));
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'A', 'telefone' => '61991234567', 'placa' => 'AAA1111', 'motivo' => 'x']);
        app(Ordens::class)->cancelar($o, 'Cliente desistiu', $ger);
        $this->travelBack();

        $i = app(Indicadores::class)->porUnidade([$u->id], CarbonImmutable::parse('2026-09-15', 'UTC'), CarbonImmutable::parse('2026-09-16', 'UTC'));
        $this->assertSame(1, $i[$u->id]['cancelamentos']);
        $this->assertSame('2026-09-15', \App\Models\AuditEvent::where('acao', 'ordem_status')->sole()->ocorrido_em->toDateString());
    }
}
