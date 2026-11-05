<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\AuditEvent;
use App\Models\Ordem;
use App\Models\PosVendaContato;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Pendencias;
use App\Services\PosVenda;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Pós-venda: lista de entregues por unidade, contatos/NPS/problema/retorno auditados, indicadores, CSV e pendência. */
class PosVendaTest extends TestCase
{
    private Unidade $unidade;
    private Unidade $outra;
    private User $recepcao;
    private User $gerente;
    private User $mecanico;
    private User $recepcaoOutra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00', 'America/Sao_Paulo'));
        $this->unidade = Unidade::factory()->create();
        $this->outra = Unidade::factory()->create();
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
        $this->recepcaoOutra = $this->usuario(Papel::Recepcao, $this->outra);
    }

    /** OS entregue com orçamento aprovado (fixture direta: o fluxo de entrega tem testes próprios). */
    private function entregue(Unidade $u, string $nome, string $telefone, string $entregueEm, int $total = 45000, ?string $placa = null): Ordem
    {
        $rec = $u->is($this->unidade) ? $this->recepcao : $this->recepcaoOutra;
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => $nome, 'telefone' => $telefone,
            'placa' => $placa ?? 'PVD'.random_int(1, 9).'A'.random_int(10, 99), 'modelo' => 'Onix', 'motivo' => 'Reforma de rodas aro 17']);
        $orc = DB::table('orcamentos')->insertGetId(['ordem_id' => $o->id, 'unidade_id' => $u->id, 'versao' => 1, 'status' => 'rascunho',
            'subtotal_centavos' => $total, 'desconto_centavos' => 0, 'total_centavos' => $total, 'criado_por' => $this->gerente->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orcamento_itens')->insert(['orcamento_id' => $orc, 'posicao' => 1, 'descricao' => 'Reforma geral de roda aro 17', 'quantidade' => '4',
            'preco_unitario_centavos' => intdiv($total, 4), 'total_centavos' => $total, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orcamentos')->where('id', $orc)->update(['status' => 'aprovado']);
        DB::table('ordens')->where('id', $o->id)->update(['status' => 'entregue']);
        DB::table('entregas')->insert(['ordem_id' => $o->id, 'unidade_id' => $u->id, 'entregue_por' => $rec->id, 'saldo_centavos' => 0,
            'excecao' => false, 'entregue_em' => CarbonImmutable::parse($entregueEm, 'America/Sao_Paulo')->utc()]);

        return $o->fresh();
    }

    private function contato(Ordem $o, array $dados, ?User $ator = null): PosVendaContato
    {
        return app(PosVenda::class)->registrarContato($o, $ator ?? $this->recepcao, $dados + ['tentativa' => 'ligacao']);
    }

    public function test_lista_entregues_do_mes_por_unidade_e_bloqueia_outra_unidade_e_mecanico(): void
    {
        $doMes = $this->entregue($this->unidade, 'Mariana Costa', '61991234567', '2026-10-05 10:00');
        $mesPassado = $this->entregue($this->unidade, 'Rafael Antigo', '61990000001', '2026-09-28 10:00');
        $daOutra = $this->entregue($this->outra, 'Cliente Outra Loja', '61990000002', '2026-10-06 10:00');
        $aberta = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Em Andamento', 'telefone' => '61990000003',
            'placa' => 'AND1A23', 'modelo' => 'Gol', 'motivo' => 'Revisão']);

        $r = $this->entrarComo($this->recepcao)->get('/pos-venda')->assertOk()
            ->assertSee($doMes->numero)->assertSee('Mariana Costa')->assertSee('(61) 99123-4567')
            ->assertSee('https://wa.me/5561991234567', false)->assertSee('R$ 450,00')->assertSee('05/10/2026')
            ->assertSee('4 × Reforma geral de roda aro 17')->assertSee(route('ordens.show', $doMes), false)
            ->assertDontSee($mesPassado->numero)->assertDontSee($daOutra->numero)->assertDontSee($aberta->numero);
        // Período escolhido inclui o mês anterior.
        $this->entrarComo($this->recepcao)->get('/pos-venda?de=2026-09-01&ate=2026-10-31')->assertOk()->assertSee($mesPassado->numero)->assertDontSee($daOutra->numero);
        // Outra unidade: filtro, ficha e registro recusados no servidor.
        $this->entrarComo($this->recepcao)->get('/pos-venda?unidade='.$this->outra->id)->assertForbidden();
        $this->entrarComo($this->recepcao)->get('/pos-venda/exportar?unidade='.$this->outra->id)->assertForbidden();
        $this->entrarComo($this->recepcao)->get("/pos-venda/{$daOutra->numero}")->assertForbidden();
        $this->entrarComo($this->recepcao)->post("/pos-venda/{$daOutra->numero}/contatos", ['tentativa' => 'ligacao', 'resultado' => 'atendeu'])->assertForbidden();
        $this->assertSame(0, PosVendaContato::count());
        // OS não entregue não entra no pós-venda.
        $this->entrarComo($this->recepcao)->get("/pos-venda/{$aberta->numero}")->assertNotFound();
        // Mecânico nunca: página, CSV, ficha e registro.
        foreach (['/pos-venda', '/pos-venda/exportar', "/pos-venda/{$doMes->numero}"] as $url) {
            $this->entrarComo($this->mecanico)->get($url)->assertForbidden();
        }
        $this->entrarComo($this->mecanico)->post("/pos-venda/{$doMes->numero}/contatos", ['tentativa' => 'ligacao', 'resultado' => 'atendeu'])->assertForbidden();
        $this->mecanico->forceFill(['permissoes_extras' => ['pos_venda']])->save();
        $this->assertFalse($this->mecanico->fresh()->pode('pos_venda'), 'pos_venda não é concedível ao mecânico');
        // Proprietário vê todas; gerente vê a própria unidade.
        $dono = User::factory()->papel(Papel::Proprietario)->create();
        $this->entrarComo($dono)->get('/pos-venda')->assertOk()->assertSee($doMes->numero)->assertSee($daOutra->numero);
        $this->entrarComo($this->gerente)->get('/pos-venda')->assertOk()->assertSee($doMes->numero)->assertDontSee($daOutra->numero);
    }

    public function test_registra_contato_com_nps_problema_negociacao_e_retorno_auditados(): void
    {
        $o = $this->entregue($this->unidade, 'Mariana Costa', '61991234567', '2026-10-05 10:00');
        $this->entrarComo($this->recepcao)->post("/pos-venda/{$o->numero}/contatos", [
            'tentativa' => 'ligacao', 'resultado' => 'atendeu', 'nota' => '6', 'comentario' => 'Achou demorado',
            'problema_servico' => '1', 'problema_descricao' => 'Roda dianteira com bolha na pintura',
            'negociacao_fora_padrao' => '1', 'negociacao_descricao' => 'Vendedor prometeu 10% sem registrar',
            'retorno_em' => '2026-10-22',
        ])->assertRedirect(route('posvenda.show', $o));
        $c = PosVendaContato::sole();
        $this->assertSame([6, true, true, '2026-10-22', $this->recepcao->id], [$c->nota, $c->problema_servico, $c->negociacao_fora_padrao, $c->retorno_em->toDateString(), $c->registrado_por]);
        $ev = AuditEvent::where('acao', 'pos_venda_contato')->sole();
        $this->assertSame([$this->recepcao->id, $this->unidade->id, $o->id], [$ev->ator_id, $ev->unidade_id, $ev->entidade_id]);
        $this->assertSame('Roda dianteira com bolha na pintura', $ev->depois['problema_descricao']);
        $this->assertSame(6, $ev->depois['nota']);
        $this->entrarComo($this->recepcao)->get("/pos-venda/{$o->numero}")->assertOk()->assertSee('Histórico de contatos')
            ->assertSee('Roda dianteira com bolha na pintura')->assertSee('Retorno agendado para 22/10/2026');

        // Validações no servidor (formulário e serviço).
        $this->entrarComo($this->recepcao)->post("/pos-venda/{$o->numero}/contatos", ['tentativa' => 'ligacao', 'resultado' => 'atendeu', 'problema_servico' => '1'])
            ->assertSessionHasErrors('problema_descricao');
        $this->entrarComo($this->recepcao)->post("/pos-venda/{$o->numero}/contatos", ['tentativa' => 'ligacao', 'resultado' => 'atendeu', 'nota' => '11'])->assertSessionHasErrors('nota');
        $this->entrarComo($this->recepcao)->post("/pos-venda/{$o->numero}/contatos", ['tentativa' => 'ligacao', 'resultado' => 'atendeu', 'retorno_em' => '2026-10-19'])->assertSessionHasErrors('retorno_em');
        $this->entrarComo($this->recepcao)->post("/pos-venda/{$o->numero}/contatos", ['tentativa' => 'fax', 'resultado' => 'atendeu'])->assertSessionHasErrors('tentativa');
        try {
            $this->contato($o, ['resultado' => 'nao_atendeu', 'nota' => 9]);
            $this->fail('nota sem o cliente atender deveria ser recusada');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nota', $e->errors());
        }
        $this->assertSame(1, PosVendaContato::count());
        $this->assertSame(1, AuditEvent::where('acao', 'pos_venda_contato')->count());
    }

    public function test_problema_vira_pendencia_resolvivel_e_sai_dos_problemas_abertos(): void
    {
        $o = $this->entregue($this->unidade, 'Mariana Costa', '61991234567', '2026-10-05 10:00');
        $c = $this->contato($o, ['resultado' => 'atendeu', 'nota' => 3, 'problema_servico' => true, 'problema_descricao' => 'Vazamento no pneu']);
        $pv = app(PosVenda::class);
        $this->assertSame([$c->id], $pv->problemasAbertos($this->gerente)->pluck('id')->all());
        $this->assertSame([$c->id], $pv->problemasAbertos($this->recepcao)->pluck('id')->all());
        $this->assertCount(0, $pv->problemasAbertos($this->recepcaoOutra), 'outra unidade não vê');
        $this->assertCount(0, $pv->problemasAbertos($this->mecanico));

        $item = app(Pendencias::class)->listar($this->gerente)->firstWhere('tipo', 'pos_venda_problema');
        $this->assertNotNull($item);
        $this->assertTrue($item['resolvivel']);
        $this->assertStringContainsString('Vazamento no pneu', $item['descricao']);
        $this->entrarComo($this->gerente)->get('/pendencias')->assertOk()->assertSee('Pós-venda: problema no serviço');
        // Recepção não resolve; gerente da unidade resolve com auditoria.
        $this->entrarComo($this->recepcao)->post("/pendencias/pos_venda_problema/{$c->id}/resolver", ['resolucao' => 'Cliente voltou e foi atendido'])->assertForbidden();
        $this->entrarComo($this->gerente)->post("/pendencias/pos_venda_problema/{$c->id}/resolver", ['resolucao' => 'Cliente voltou e o pneu foi trocado'])->assertSessionHasNoErrors();
        $this->assertTrue(AuditEvent::where('acao', 'pendencia_resolvida')->where('entidade_id', $o->id)->exists());
        $this->assertCount(0, $pv->problemasAbertos($this->gerente));
        $this->assertNull(app(Pendencias::class)->listar($this->gerente)->firstWhere('tipo', 'pos_venda_problema'));
        // Contato sem problema não vira pendência resolvível.
        $sem = $this->contato($o, ['resultado' => 'atendeu', 'nota' => 10]);
        $this->entrarComo($this->gerente)->post("/pendencias/pos_venda_problema/{$sem->id}/resolver", ['resolucao' => 'Não era pendência'])->assertNotFound();
    }

    public function test_indicadores_do_periodo_e_retornos_pendentes(): void
    {
        $a = $this->entregue($this->unidade, 'Ana Promotora', '61990000011', '2026-10-02 10:00');
        $b = $this->entregue($this->unidade, 'Beto Detrator', '61990000012', '2026-10-03 10:00');
        $c = $this->entregue($this->unidade, 'Caio Sem Atender', '61990000013', '2026-10-04 10:00');
        $this->entregue($this->unidade, 'Dora Sem Contato', '61990000014', '2026-10-05 10:00');
        $this->entregue($this->outra, 'Outra Loja', '61990000015', '2026-10-05 10:00');

        $this->contato($a, ['resultado' => 'nao_atendeu']);
        $this->contato($a, ['resultado' => 'atendeu', 'nota' => 10]);
        $this->contato($b, ['resultado' => 'atendeu', 'nota' => 5, 'problema_servico' => true, 'problema_descricao' => 'Risco na roda']);
        $this->contato($c, ['resultado' => 'nao_atendeu', 'retorno_em' => '2026-10-20']); // hoje
        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00', 'America/Sao_Paulo'));
        $this->contato($b, ['resultado' => 'atendeu', 'retorno_em' => '2026-10-22'], $this->gerente); // hoje (novo "hoje")

        $i = app(PosVenda::class)->indicadores($this->recepcao, ['de' => '2026-10-01', 'ate' => '2026-10-31']);
        $this->assertSame(4, $i['atendimentos']);
        $this->assertSame(2, $i['contatados']);
        $this->assertSame(50, $i['percentual_contatados']);
        $this->assertSame(0, $i['nps'], '1 promotor e 1 detrator em 2 respostas');
        $this->assertSame(2, $i['respostas_nps']);
        $this->assertSame(1, $i['problemas_abertos']);
        $this->assertSame(1, $i['retornos_hoje']);
        $this->assertSame(1, $i['retornos_atrasados'], 'retorno de 20/10 vencido');
        $this->assertSame([$c->id, $b->id], app(PosVenda::class)->retornosPendentes($this->recepcao)->pluck('ordem_id')->all());
        $this->assertCount(0, app(PosVenda::class)->retornosPendentes($this->recepcaoOutra));
        $this->assertCount(0, app(PosVenda::class)->retornosPendentes($this->mecanico));
        // Novo contato encerra o retorno anterior.
        $this->contato($c, ['resultado' => 'atendeu', 'nota' => 9]);
        $this->assertSame([$b->id], app(PosVenda::class)->retornosPendentes($this->recepcao)->pluck('ordem_id')->all());
        // Filtro de situação e tela com indicadores.
        $this->entrarComo($this->recepcao)->get('/pos-venda?de=2026-10-01&ate=2026-10-31&situacao=sem_contato')->assertOk()
            ->assertSee('Dora Sem Contato')->assertDontSee('Ana Promotora');
        $this->entrarComo($this->recepcao)->get('/pos-venda?de=2026-10-01&ate=2026-10-31&situacao=problema')->assertOk()
            ->assertSee('Beto Detrator')->assertDontSee('Ana Promotora');
        $this->entrarComo($this->recepcao)->get('/pos-venda?de=2026-10-01&ate=2026-10-31')->assertOk()->assertSee('NPS do período')->assertSee('Retornos atrasados');
    }

    public function test_csv_exporta_lista_com_escape_de_formula_e_auditoria(): void
    {
        $o = $this->entregue($this->unidade, '=HYPERLINK("http://mal.example","x")', '61991234567', '2026-10-05 10:00');
        $this->entregue($this->outra, 'Outra Loja Csv', '61990000002', '2026-10-06 10:00');
        $this->contato($o, ['resultado' => 'atendeu', 'nota' => 9, 'comentario' => '+cmd']);
        $csv = $this->entrarComo($this->recepcao)->get('/pos-venda/exportar')->assertOk()->streamedContent();
        $this->assertStringContainsString('Telefone', $csv);
        $this->assertStringContainsString($o->numero, $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'fórmula neutralizada');
        $this->assertDoesNotMatchRegularExpression('/(^|;)"?=HYPERLINK/m', $csv);
        $this->assertStringContainsString('(61) 99123-4567', $csv);
        $this->assertStringNotContainsString('Outra Loja Csv', $csv);
        $this->assertTrue(AuditEvent::where('acao', 'pos_venda_exportado')->where('ator_id', $this->recepcao->id)->exists());
    }
}
