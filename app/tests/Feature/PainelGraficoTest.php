<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gráfico interativo do painel: séries em dinheiro só para quem pode vê-las (autorização no servidor) e filtro de data
 * em /ordens (dia local de Brasília, unidade, visibilidade e validação).
 */
class PainelGraficoTest extends TestCase
{
    private Unidade $a;
    private Unidade $b;
    private User $gerA;
    private User $recA;
    private User $mecA;
    private Ordem $o1; // entrada 10/10 23:30 (Brasília) = 11/10 02:30 UTC; aprovada e paga em 12/10
    private Ordem $o2; // entrada 11/10 09:00; entregue em 13/10
    private Ordem $ob; // unidade B, entrada 10/10

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado']);
        $this->a = Unidade::factory()->create(['nome' => 'Oficina A']);
        $this->b = Unidade::factory()->create(['nome' => 'Oficina B']);
        $this->gerA = User::factory()->papel(Papel::Gerente, $this->a)->create();
        $this->recA = User::factory()->papel(Papel::Recepcao, $this->a)->create(['name' => 'Ana Recepção']);
        $this->mecA = $this->usuario(Papel::Mecanico, $this->a);

        $this->o1 = $this->entrada($this->a, 'AAA1A11', '2026-10-10 23:30');
        $this->o2 = $this->entrada($this->a, 'AAA1A12', '2026-10-11 09:00');
        $this->ob = $this->entrada($this->b, 'BBB2B22', '2026-10-10 10:00');

        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'America/Sao_Paulo'));
        app(Ordens::class)->iniciarDiagnostico($this->o1, $this->mecA);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($this->o1, $this->gerA, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '1234.56']]);
        $s->enviar($v, $this->gerA);
        $s->decidir($v->id, $this->o1->id, 'aprovado', 'link_cliente');
        app(Financeiro::class)->registrarManual($this->o1->fresh(), $this->gerA, 'dinheiro', 123456);

        // Entrega registrada diretamente (o fluxo de entrega tem seus próprios testes): 13/10 08:00 Brasília.
        DB::table('entregas')->insert(['ordem_id' => $this->o2->id, 'unidade_id' => $this->a->id, 'entregue_por' => $this->gerA->id,
            'saldo_centavos' => 0, 'excecao' => false, 'entregue_em' => CarbonImmutable::parse('2026-10-13 08:00', 'America/Sao_Paulo')->utc()]);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'America/Sao_Paulo'));
    }

    private function entrada(Unidade $u, string $placa, string $quando): Ordem
    {
        $this->travelTo(CarbonImmutable::parse($quando, 'America/Sao_Paulo'));
        $rec = $u->is($this->a) ? $this->recA : $this->usuario(Papel::Recepcao, $u);

        return app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'Cliente '.$placa, 'telefone' => '6199'.random_int(100000, 999999).'1', // nunca termina em 0000 (o simulador recusa de propósito)
            'placa' => $placa, 'motivo' => 'Revisão']);
    }

    /** Dados do gráfico exatamente como foram para a página (JSON em <script type="application/json">). */
    private function dadosGrafico(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" data-grafico-dados>(.*?)</script>#s', $html, $m), 'JSON do gráfico ausente');

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_sem_permissao_financeira_a_pagina_nao_recebe_series_em_dinheiro(): void
    {
        // Recepção tem ver_valores, mas não indicadores financeiros (ver_auditoria): o painel nunca mostrou dinheiro a ela.
        $r = $this->entrarComo($this->recA)->get('/?de=2026-10-08&ate=2026-10-14')->assertOk();
        $g = $this->dadosGrafico($r->getContent());
        $this->assertSame(['entradas', 'entregas'], array_keys($g['metricas']));
        $this->assertSame('entradas', $g['padrao']);
        $r->assertDontSee('1.234,56')->assertDontSee('123456')->assertDontSee('"recebido"', false)->assertDontSee('"aprovado"', false)
            ->assertDontSee('data-metrica="recebido"', false)->assertSee('Entradas no período');
        // Entrada de 10/10 23:30 (Brasília) conta no dia 10, não no 11 (UTC).
        $dias = array_column($g['grupos'], 'chave');
        $this->assertSame(1, $g['metricas']['entradas']['valores'][array_search('2026-10-10', $dias)]);
        $this->assertSame(1, $g['metricas']['entradas']['valores'][array_search('2026-10-11', $dias)]);
        $this->assertSame(1, $g['metricas']['entregas']['valores'][array_search('2026-10-13', $dias)]);

        // Mesmo um gerente sem ver_valores (permissão removida) não recebe dinheiro.
        $semValores = User::factory()->papel(Papel::Gerente, $this->a)->create();
        \Illuminate\Support\Facades\Gate::before(fn ($u, $hab) => $u->is($semValores) && $hab === 'ver_valores' ? false : null);
        $g2 = $this->dadosGrafico($this->entrarComo($semValores)->get('/?de=2026-10-08&ate=2026-10-14')->assertOk()->getContent());
        $this->assertSame(['entradas', 'entregas'], array_keys($g2['metricas']));
    }

    public function test_com_permissao_financeira_recebe_as_quatro_series_e_links_por_metrica(): void
    {
        $r = $this->entrarComo($this->gerA)->get('/?de=2026-10-08&ate=2026-10-14')->assertOk();
        $g = $this->dadosGrafico($r->getContent());
        $this->assertSame(['recebido', 'aprovado', 'entradas', 'entregas'], array_keys($g['metricas']));
        $dias = array_column($g['grupos'], 'chave');
        $i12 = array_search('2026-10-12', $dias);
        $this->assertSame(123456, $g['metricas']['recebido']['valores'][$i12]);
        $this->assertSame(123456, $g['metricas']['aprovado']['valores'][$i12]);
        $this->assertSame('R$ 1.234,56', $g['metricas']['recebido']['total']);
        $this->assertSame('Seg, 12/10/2026', $g['grupos'][$i12]['titulo']);
        $this->assertSame('pagamento', $g['metricas']['recebido']['data']);
        $r->assertSee('Recebido no período')->assertSee('data-metrica="aprovado"', false)
            ->assertSee(e(route('ordens.index', ['de' => '2026-10-12', 'ate' => '2026-10-12', 'data' => 'pagamento'])), false);
    }

    public function test_grupos_semanais_e_mensais_recortam_o_periodo(): void
    {
        $serie = ['dias' => [], 'entradas' => [], 'entregas' => [], 'aprovado' => [], 'recebido' => []];
        foreach (CarbonImmutable::parse('2026-01-15')->daysUntil('2026-06-10') as $d) {
            $serie['dias'][] = $d->format('Y-m-d');
            foreach (['entradas', 'entregas', 'aprovado', 'recebido'] as $k) {
                $serie[$k][] = 1;
            }
        }
        $g = \App\Services\SeriesPainel::grafico($serie, false, '2026-01-15', '2026-06-10');
        $this->assertSame('mês', $g['escala']);
        $this->assertSame(['2026-01-15', '2026-01-31'], [$g['grupos'][0]['de'], $g['grupos'][0]['ate']]);
        $this->assertSame(['2026-06-01', '2026-06-10'], [end($g['grupos'])['de'], end($g['grupos'])['ate']]);
        $this->assertSame(17, $g['metricas']['entradas']['valores'][0]);

        $s2 = array_map(fn ($v) => is_array($v) ? array_slice($v, 0, 40) : $v, $serie);
        $g2 = \App\Services\SeriesPainel::grafico($s2, true, '2026-01-15', '2026-02-23');
        $this->assertSame('semana', $g2['escala']);
        // 15/01/2026 é quinta: a semana de agrupar() (startOfWeek, domingo 11/01) é recortada para 15/01 a 17/01.
        $this->assertSame(['2026-01-15', '2026-01-17'], [$g2['grupos'][0]['de'], $g2['grupos'][0]['ate']]);
        $this->assertSame('Semana de 15/01 a 17/01/2026', $g2['grupos'][0]['titulo']);
        $this->assertSame(3, $g2['metricas']['recebido']['valores'][0]);
    }

    public function test_filtro_de_data_em_ordens_por_entrada_entrega_pagamento_e_aprovacao(): void
    {
        $ids = fn ($r) => $r->viewData('ordens')->pluck('id')->sort()->values()->all();

        // Entrada no dia 10 (fuso de Brasília): o1 (23:30 local, já 11/10 em UTC). A OS da unidade B não aparece para o gerente A.
        $r = $this->entrarComo($this->gerA)->get('/ordens?de=2026-10-10&ate=2026-10-10&data=entrada')->assertOk()
            ->assertSee('Filtro ativo')->assertSee('Ordens com entrada em 10/10/2026')->assertSee('Limpar filtro de data');
        $this->assertSame([$this->o1->id], $ids($r));
        $this->assertSame([$this->o2->id], $ids($this->entrarComo($this->gerA)->get('/ordens?de=2026-10-11&data=entrada')));
        $this->assertSame([$this->o2->id], $ids($this->entrarComo($this->gerA)->get('/ordens?de=2026-10-13&ate=2026-10-13&data=entrega')));
        $this->assertSame([], $ids($this->entrarComo($this->gerA)->get('/ordens?de=2026-10-12&ate=2026-10-12&data=entrega')->assertSee('Nenhuma ordem neste período')));
        $this->assertSame([$this->o1->id], $ids($this->entrarComo($this->gerA)->get('/ordens?de=2026-10-12&ate=2026-10-12&data=pagamento')));
        $this->assertSame([$this->o1->id], $ids($this->entrarComo($this->gerA)->get('/ordens?de=2026-10-12&ate=2026-10-12&data=aprovacao')));
        $this->assertSame([], $ids($this->entrarComo($this->gerA)->get('/ordens?de=2026-10-11&ate=2026-10-11&data=pagamento')));
        // Intervalo (semana) com responsável: só OS registradas por Ana.
        $r = $this->entrarComo($this->gerA)->get("/ordens?de=2026-10-08&ate=2026-10-14&data=entrada&responsavel={$this->recA->id}")->assertOk()
            ->assertSee('de 08/10/2026 a 14/10/2026')->assertSee('entrada registrada por Ana Recepção');
        $this->assertSame([$this->o1->id, $this->o2->id], $ids($r));

        // Proprietário vê as duas unidades; com unidade=B, só a OS de B.
        $dono = User::factory()->proprietario()->create();
        $this->assertSame([$this->o1->id, $this->ob->id], $ids($this->entrarComo($dono)->get('/ordens?de=2026-10-10&data=entrada')));
        $this->assertSame([$this->ob->id], $ids($this->entrarComo($dono)->get("/ordens?de=2026-10-10&data=entrada&unidade={$this->b->id}")));
    }

    public function test_filtro_de_data_respeita_unidade_visibilidade_e_validacao(): void
    {
        // Outra unidade: 403.
        $this->entrarComo($this->gerA)->get("/ordens?de=2026-10-10&data=entrada&unidade={$this->b->id}")->assertForbidden();

        // Mecânico: só a própria fila (ou sem responsável) e nunca datas financeiras.
        $this->o1->forceFill(['mecanico_id' => $this->usuario(Papel::Mecanico, $this->a)->id])->save(); // fila de outro mecânico
        $r = $this->entrarComo($this->mecA)->get('/ordens?de=2026-10-08&ate=2026-10-14&data=entrada');
        if ($r->status() === 200) {
            $this->assertNotContains($this->o1->id, $r->viewData('ordens')->pluck('id')->all());
            $this->assertNotContains($this->ob->id, $r->viewData('ordens')->pluck('id')->all());
        } else {
            $this->assertContains($r->status(), [302, 403]); // mecânico pode nem ter acesso à lista geral
        }
        $this->entrarComo($this->mecA)->get('/ordens?de=2026-10-12&data=pagamento')->assertForbidden();
        $this->entrarComo($this->mecA)->get('/ordens?de=2026-10-12&data=aprovacao')->assertForbidden();

        // Validação: formato, ordem das datas, tipo desconhecido, "até" sem "de", período acima de 2 anos.
        foreach (['de=12/10/2026', 'de=2026-02-30', 'de=2026-10-12&ate=2026-10-11', 'de=2026-10-12&data=caixa', 'ate=2026-10-12', 'data=entrada'] as $q) {
            $this->entrarComo($this->gerA)->get('/ordens?'.$q)->assertSessionHasErrors();
        }
        $this->entrarComo($this->gerA)->get('/ordens?de=2020-01-01&ate=2026-10-12')->assertStatus(422);
    }
}
