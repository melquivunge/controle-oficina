<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Models\AuditEvent;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Execucao;
use App\Services\Orcamentos;
use App\Services\Ordens;
use App\Services\PrazosEntrega;
use App\Support\DiasUteis;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Bloco A: data de entrega prevista em dias úteis (seg–sex, sem feriados), definida por gerente/proprietário, com atraso. */
class PrazoEntregaTest extends TestCase
{
    private Unidade $unidade;
    private User $gerente;
    private User $recepcao;
    private User $mecanico;

    protected function setUp(): void
    {
        parent::setUp();
        // Quinta-feira, 29/10/2026, meio-dia em Brasília. 02/11 (segunda) é feriado nacional.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-29 12:00', 'America/Sao_Paulo'));
        $this->unidade = Unidade::factory()->create();
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ordem(string $placa = 'ABC1D23'): Ordem
    {
        $o = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Cliente '.$placa, 'telefone' => '61991234567', 'placa' => $placa, 'motivo' => 'Rodas']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->mecanico);

        return $o->fresh();
    }

    private function d(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::parse($ymd, 'America/Sao_Paulo');
    }

    public function test_dias_uteis_pulam_fim_de_semana_e_feriado(): void
    {
        $this->assertSame('2026-11-04', DiasUteis::somar($this->d('2026-10-29'), 3)->format('Y-m-d')); // sex 30, (sáb, dom, feriado 02/11), ter 03, qua 04
        $this->assertSame('2026-10-26', DiasUteis::somar($this->d('2026-10-23'), 1)->format('Y-m-d')); // sexta + 1 = segunda
        $this->assertSame('2026-11-23', DiasUteis::somar($this->d('2026-11-19'), 1)->format('Y-m-d')); // 20/11 (Consciência Negra) é feriado
        $this->assertSame('2027-01-04', DiasUteis::somar($this->d('2026-12-31'), 1)->format('Y-m-d')); // 01/01 + fim de semana
        $this->assertFalse(DiasUteis::ehUtil($this->d('2026-10-31'))); // sábado
        $this->assertFalse(DiasUteis::ehUtil($this->d('2026-11-02'))); // feriado
        $this->assertTrue(DiasUteis::ehUtil($this->d('2026-11-03')));
        $this->assertSame(3, DiasUteis::entre($this->d('2026-10-29'), $this->d('2026-11-04')));

        // Lista configurável: sem feriados, a mesma conta termina antes.
        config(['oficina.feriados' => []]);
        $this->assertSame('2026-11-03', DiasUteis::somar($this->d('2026-10-29'), 3)->format('Y-m-d'));
        config(['oficina.feriados' => ['2026-10-30']]);
        $this->assertSame('2026-11-04', DiasUteis::somar($this->d('2026-10-29'), 3)->format('Y-m-d'));
    }

    public function test_gerente_define_por_dias_uteis_ou_data_e_alteracao_e_auditada(): void
    {
        $o = $this->ordem();
        $s = app(PrazosEntrega::class);
        $s->definir($o, $this->gerente, diasUteis: 3);
        $o->refresh();
        $this->assertSame('2026-11-04', $o->entrega_prevista_em->format('Y-m-d'));
        $this->assertSame($this->gerente->id, $o->entrega_prevista_por);

        foreach (['2026-11-02' => 'feriado', '2026-10-31' => 'sábado', '2026-10-28' => 'passado'] as $data => $caso) {
            try {
                $s->definir($o, $this->gerente, data: $data);
                $this->fail("data inválida aceita ({$caso})");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('prazo_data', $e->errors());
            }
        }
        $this->entrarComo($this->gerente)->post("/ordens/{$o->numero}/prazo-entrega", ['prazo_data' => '2026-11-06'])->assertRedirect()->assertSessionHas('sucesso');
        $this->assertSame('2026-11-06', $o->fresh()->entrega_prevista_em->format('Y-m-d'));
        $this->entrarComo($this->gerente)->post("/ordens/{$o->numero}/prazo-entrega", ['prazo_dias' => 0])->assertSessionHasErrors('prazo_dias');
        $this->entrarComo($this->gerente)->post("/ordens/{$o->numero}/prazo-entrega", ['prazo_dias' => 2, 'prazo_data' => '2026-11-06'])->assertSessionHasErrors('prazo_dias');

        $eventos = AuditEvent::where('acao', 'prazo_entrega_definido')->orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame([null, '2026-11-04'], [$eventos[0]->antes['entrega_prevista_em'], $eventos[0]->depois['entrega_prevista_em']]);
        $this->assertSame(['2026-11-04', '2026-11-06'], [$eventos[1]->antes['entrega_prevista_em'], $eventos[1]->depois['entrega_prevista_em']]);
        $this->assertSame($this->gerente->id, $eventos[1]->ator_id);
    }

    public function test_somente_gerente_ou_proprietario_da_unidade_define_prazo(): void
    {
        $o = $this->ordem();
        foreach ([$this->recepcao, $this->mecanico, $this->usuario(Papel::Gerente)] as $u) {
            $this->entrarComo($u)->post("/ordens/{$o->numero}/prazo-entrega", ['prazo_dias' => 3])->assertForbidden();
        }
        try {
            app(PrazosEntrega::class)->definir($o, $this->recepcao, diasUteis: 3);
            $this->fail('recepção não define prazo');
        } catch (AuthorizationException) {
        }
        $this->assertNull($o->fresh()->entrega_prevista_em);
        $this->entrarComo($this->usuario(Papel::Proprietario))->post("/ordens/{$o->numero}/prazo-entrega", ['prazo_dias' => 1])->assertRedirect();
        $this->assertSame('2026-10-30', $o->fresh()->entrega_prevista_em->format('Y-m-d'));
        // Recepção vê a data (para informar o cliente), mas não o formulário.
        $this->entrarComo($this->recepcao)->get("/ordens/{$o->numero}")->assertOk()->assertSee('30/10/2026')->assertDontSee('Prazo em dias úteis');
        $this->entrarComo($this->gerente)->get("/ordens/{$o->numero}")->assertOk()->assertSee('Prazo em dias úteis');
    }

    public function test_atraso_destacado_na_fila_lista_painel_e_os_e_servico_de_atrasadas(): void
    {
        $atrasada = $this->ordem('AAA1A11');
        $noPrazo = $this->ordem('BBB2B22');
        $semPrazo = $this->ordem('CCC3C33');
        $pronta = $this->ordem('DDD4D44');
        $s = app(PrazosEntrega::class);
        $s->definir($atrasada, $this->gerente, diasUteis: 1); // 30/10
        $s->definir($noPrazo, $this->gerente, diasUteis: 3); // 04/11
        $s->definir($pronta, $this->gerente, diasUteis: 1); // 30/10, mas ficará pronta
        $orc = app(Orcamentos::class);
        $v = $orc->salvarRascunho($pronta, $this->gerente, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '100']]);
        $orc->enviar($v, $this->gerente);
        $orc->decidir($v->id, $pronta->id, 'aprovado', 'link_cliente');
        $exec = app(Execucao::class);
        $exec->iniciar($pronta->fresh(), $this->mecanico);
        $exec->concluirItem($pronta->fresh(), $v->fresh()->itens->first()->id, $this->mecanico);
        $exec->marcarPronto($pronta->fresh(), $this->mecanico);
        $this->assertSame(StatusOrdem::ProntoParaEntrega, $pronta->fresh()->status);

        // No próprio dia previsto ainda não está atrasada.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-30 18:00', 'America/Sao_Paulo'));
        $this->assertFalse($atrasada->fresh()->entregaAtrasada());
        $this->assertCount(0, $s->atrasadas($this->gerente));

        // Terça 03/11: 30/10 venceu.
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-03 09:00', 'America/Sao_Paulo'));
        $this->assertTrue($atrasada->fresh()->entregaAtrasada());
        $this->assertFalse($noPrazo->fresh()->entregaAtrasada());
        $this->assertFalse($pronta->fresh()->entregaAtrasada());
        $this->assertSame([$atrasada->id], $s->atrasadas($this->gerente)->pluck('id')->all());
        $this->assertSame([$atrasada->id], $s->atrasadas($this->mecanico)->pluck('id')->all());
        $this->assertCount(0, $s->atrasadas($this->usuario(Papel::Gerente))); // outra unidade
        $this->assertCount(1, $s->atrasadas($this->usuario(Papel::Proprietario)));

        // Fila do mecânico: ordenada por prazo (atrasada primeiro, sem prazo por último) e destacada.
        $fila = $this->entrarComo($this->mecanico)->get('/fila')->assertOk()->assertSee('Atrasada')
            ->assertSeeInOrder([$atrasada->numero, $noPrazo->numero, $semPrazo->numero]);
        $this->assertSame([$atrasada->id, $noPrazo->id, $semPrazo->id], $fila->viewData('minhas')->reject(fn ($o) => $o->id === $pronta->id)->pluck('id')->values()->all());
        // Lista de ordens com filtro de atrasadas, OS e painel.
        $this->entrarComo($this->recepcao)->get('/ordens?atrasadas=1')->assertOk()->assertSee($atrasada->numero)->assertDontSee($noPrazo->numero)->assertDontSee($pronta->numero);
        $this->entrarComo($this->recepcao)->get('/ordens')->assertOk()->assertSee('Entrega prevista 30/10');
        $this->entrarComo($this->recepcao)->get("/ordens/{$atrasada->numero}")->assertOk()->assertSee('Entrega atrasada');
        $this->entrarComo($this->recepcao)->get("/ordens/{$noPrazo->numero}")->assertOk()->assertDontSee('Entrega atrasada');
        $this->assertSame(1, $this->entrarComo($this->recepcao)->get('/')->assertOk()->assertSee('Entregas atrasadas')->viewData('atrasadas'));
    }

    public function test_prazo_ao_enviar_e_ao_aprovar_presencialmente(): void
    {
        $o = $this->ordem();
        $orc = app(Orcamentos::class);
        $v = $orc->salvarRascunho($o, $this->gerente, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '100']]);
        // Prazo inválido não envia o orçamento.
        $this->entrarComo($this->gerente)->post("/ordens/{$o->numero}/orcamentos/{$v->id}/enviar", ['prazo_dias' => 500])->assertSessionHasErrors('prazo_dias');
        $this->assertSame('rascunho', $v->fresh()->status);
        $this->entrarComo($this->gerente)->post("/ordens/{$o->numero}/orcamentos/{$v->id}/enviar", ['prazo_dias' => 5])->assertRedirect();
        $this->assertSame('2026-11-06', $o->fresh()->entrega_prevista_em->format('Y-m-d'));
        $this->entrarComo($this->gerente)->post("/ordens/{$o->numero}/orcamentos/{$v->id}/presencial", ['decisao' => 'aprovado', 'observacao' => 'Aprovou no balcão', 'prazo_dias' => 7])->assertRedirect();
        $this->assertSame('2026-11-10', $o->fresh()->entrega_prevista_em->format('Y-m-d'));
        $this->assertSame(2, AuditEvent::where('acao', 'prazo_entrega_definido')->count());
    }
}
