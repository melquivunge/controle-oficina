<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\FeriadoUnidade;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use App\Services\PrazosEntrega;
use App\Services\SeriesPainel;
use App\Support\DiasUteis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Feriados estaduais/municipais por unidade no prazo em dias úteis; painel com período de 2 anos e vendas por dia da semana. */
class FeriadosPainelSemanaTest extends TestCase
{
    private Unidade $a;
    private Unidade $b;
    private User $sup;
    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado']);
        Storage::fake('local');
        $this->a = Unidade::factory()->create(['nome' => 'Unidade Goiânia']);
        $this->b = Unidade::factory()->create(['nome' => 'Unidade Brasília']);
        $this->dono = $this->usuario(Papel::Proprietario);
        $this->sup = User::factory()->papel(Papel::Supervisor, $this->a)->create();
        $this->sup->unidadesSupervisionadas()->sync([$this->a->id]);
    }

    private function ordem(Unidade $u, string $placa = 'GYN1A23'): \App\Models\Ordem
    {
        return app(Entradas::class)->registrar($this->usuario(Papel::Recepcao, $u), ['unidade_id' => $u->id, 'nome' => 'Cliente', 'telefone' => '6299123'.random_int(1000, 9999),
            'placa' => $placa, 'motivo' => 'Reforma de rodas']);
    }

    public function test_feriado_local_adia_prazo_so_na_unidade_dele(): void
    {
        // Quinta 05/11/2026; +1 dia útil = sexta 06/11. Feriado municipal em 06/11 só na unidade A → segunda 09/11.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'America/Sao_Paulo'));
        $this->assertSame('2026-11-06', DiasUteis::somar(DiasUteis::hoje(), 1, $this->a->id)->format('Y-m-d'));

        $this->entrarComo($this->sup)->post('/feriados', ['unidade_id' => $this->a->id, 'data' => '2026-11-06', 'descricao' => 'Aniversário da cidade'])->assertRedirect();
        $f = FeriadoUnidade::sole();
        $this->assertDatabaseHas('audit_events', ['acao' => 'feriado_unidade_criado', 'unidade_id' => $this->a->id, 'ator_id' => $this->sup->id]);
        // Data repetida recusada.
        $this->entrarComo($this->sup)->post('/feriados', ['unidade_id' => $this->a->id, 'data' => '2026-11-06', 'descricao' => 'Duplicado'])->assertSessionHasErrors('data');

        $this->assertSame('2026-11-09', DiasUteis::somar(DiasUteis::hoje(), 1, $this->a->id)->format('Y-m-d'));
        $this->assertSame('2026-11-06', DiasUteis::somar(DiasUteis::hoje(), 1, $this->b->id)->format('Y-m-d'));
        $this->assertSame('2026-11-06', DiasUteis::somar(DiasUteis::hoje(), 1)->format('Y-m-d'), 'sem unidade: só nacionais');

        // Prazo da OS (fluxo real): unidade A pula o feriado; unidade B não.
        $oa = $this->ordem($this->a);
        $ob = $this->ordem($this->b, 'BSB1A23');
        $ger = $this->usuario(Papel::Gerente, $this->b);
        app(PrazosEntrega::class)->definir($oa, $this->sup, diasUteis: 1);
        app(PrazosEntrega::class)->definir($ob, $ger, diasUteis: 1);
        $this->assertSame('2026-11-09', $oa->fresh()->entrega_prevista_em->format('Y-m-d'));
        $this->assertSame('2026-11-06', $ob->fresh()->entrega_prevista_em->format('Y-m-d'));
        // Data explícita no feriado local é recusada na unidade A.
        $this->entrarComo($this->sup)->post("/ordens/{$oa->numero}/prazo-entrega", ['prazo_data' => '2026-11-06'])->assertSessionHasErrors('prazo_data');

        // Remoção auditada; prazo volta a contar o dia.
        $this->entrarComo($this->sup)->delete("/feriados/{$f->id}")->assertRedirect();
        $this->assertDatabaseMissing('feriados_unidade', ['id' => $f->id]);
        $this->assertDatabaseHas('audit_events', ['acao' => 'feriado_unidade_removido', 'unidade_id' => $this->a->id]);
        $this->assertSame('2026-11-06', DiasUteis::somar(DiasUteis::hoje(), 1, $this->a->id)->format('Y-m-d'));
    }

    public function test_feriados_autorizacao_por_unidade(): void
    {
        $fb = FeriadoUnidade::create(['unidade_id' => $this->b->id, 'data' => '2026-12-08', 'descricao' => 'Feriado B']);
        $this->entrarComo($this->sup)->get('/feriados')->assertOk()->assertSee('Unidade Goiânia')->assertDontSee('Feriado B');
        $this->entrarComo($this->sup)->get("/feriados?unidade={$this->b->id}")->assertForbidden();
        $this->entrarComo($this->sup)->post('/feriados', ['unidade_id' => $this->b->id, 'data' => '2026-12-09', 'descricao' => 'Fora da unidade'])->assertForbidden();
        $this->entrarComo($this->sup)->delete("/feriados/{$fb->id}")->assertForbidden();
        $this->assertSame(1, FeriadoUnidade::count());
        // Gerente e recepção não gerem feriados; proprietário gere qualquer unidade.
        $this->entrarComo($this->usuario(Papel::Gerente, $this->b))->get('/feriados')->assertForbidden();
        $this->entrarComo($this->usuario(Papel::Recepcao, $this->a))->post('/feriados', ['unidade_id' => $this->a->id, 'data' => '2026-12-09', 'descricao' => 'x y z'])->assertForbidden();
        $this->entrarComo($this->dono)->get("/feriados?unidade={$this->b->id}")->assertOk()->assertSee('Feriado B');
        $this->entrarComo($this->dono)->get('/configuracoes/unidades')->assertOk()->assertSee('Feriados locais');
    }

    public function test_periodo_de_dois_anos_agrupa_por_mes_e_limite(): void
    {
        $r = $this->entrarComo($this->dono)->get('/?periodo=730')->assertOk()->assertSee('2 anos')->assertSee('Por mês');
        $this->assertSame(730, count($r->viewData('serie')['dias']));
        $meses = count(SeriesPainel::agrupar($r->viewData('serie')['dias'], $r->viewData('serie')['recebido']));
        $this->assertTrue($meses >= 24 && $meses <= 25, "agrupado por mês: {$meses}");
        $this->entrarComo($this->dono)->get('/?de=2024-10-30&ate=2026-10-30')->assertOk(); // 730 dias entre as datas
        $this->entrarComo($this->dono)->get('/?de=2024-01-01&ate=2026-10-30')->assertStatus(422);
        $this->entrarComo($this->dono)->get('/painel/exportar?periodo=730')->assertOk();
    }

    public function test_vendas_por_dia_da_semana_destaca_melhor_pior_e_dias_sem_venda(): void
    {
        $ger = $this->usuario(Papel::Gerente, $this->a);
        $mec = $this->usuario(Papel::Mecanico, $this->a);
        // Segunda 26/10/2026: R$ 300; quarta 28/10: R$ 100. Período 7 dias até sexta 30/10 (sáb 24 .. sex 30).
        foreach ([['2026-10-26 10:00', 30000, 'GYN1A01'], ['2026-10-28 10:00', 10000, 'GYN1A02']] as [$quando, $valor, $placa]) {
            $this->travelTo(CarbonImmutable::parse($quando, 'America/Sao_Paulo'));
            $o = $this->ordem($this->a, $placa);
            app(Ordens::class)->iniciarDiagnostico($o, $mec);
            $v = app(Orcamentos::class)->salvarRascunho($o, $ger, [['descricao' => 'Reforma', 'quantidade' => '1', 'preco' => (string) ($valor / 100)]]);
            app(Orcamentos::class)->enviar($v, $ger);
            app(Orcamentos::class)->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
            app(Financeiro::class)->registrarManual($o->fresh(), $ger, 'dinheiro', $valor);
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-30 18:00', 'America/Sao_Paulo'));

        $r = $this->entrarComo($ger)->get('/?periodo=7')->assertOk()->assertSee('Vendas por dia da semana')->assertSee('Melhor')->assertSee('Pior');
        $s = $r->viewData('semana');
        $this->assertSame(30000, $s['dias'][1]['recebido']);
        $this->assertSame(10000, $s['dias'][3]['recebido']);
        $this->assertSame(1, $s['dias'][1]['entradas']);
        $this->assertSame(1, $s['melhor'], 'segunda');
        $this->assertContains($s['pior'], [2, 4, 5, 6, 7], 'um dia sem venda é o pior');
        $this->assertSame(5, $s['sem_venda'], '7 dias, 2 com venda');
        $this->assertSame(7, $s['total_dias']);

        // Filtro de unidade respeitado: B não tem vendas → sem destaque.
        $sb = $this->entrarComo($this->dono)->get("/?periodo=7&unidade={$this->b->id}")->assertOk()->viewData('semana');
        $this->assertNull($sb['melhor']);
        $this->assertSame(7, $sb['sem_venda']);

        // CSV por dia da semana (mesmos filtros).
        $csv = $this->entrarComo($ger)->get('/painel/exportar?periodo=7&visao=dia_semana')->assertOk()->streamedContent();
        $this->assertStringContainsString('dia_semana;unidade;de;ate', $csv);
        $this->assertStringContainsString('Segunda;Todas;2026-10-24;2026-10-30;;300,00;1;1;0;300,00;melhor', $csv);

        // Recepção (sem valores) vê o cartão de entradas, sem R$.
        $rec = $this->entrarComo($this->usuario(Papel::Recepcao, $this->a))->get('/?periodo=7')->assertOk()->assertSee('Entradas por dia da semana');
        $this->assertSame('entradas', $rec->viewData('semana')['metrica']);
    }
}
