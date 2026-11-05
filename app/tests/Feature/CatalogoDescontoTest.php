<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\AprovacaoInterna;
use App\Models\AuditEvent;
use App\Models\CatalogoItem;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Services\AprovacoesInternas;
use App\Services\Catalogo;
use App\Services\Entradas;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogoDescontoTest extends TestCase
{
    private Unidade $unidade;
    private Unidade $outra;
    private User $dono;
    private User $gerente;
    private User $gerente2;
    private User $recepcao;
    private User $mecanico;
    private Ordem $ordem;
    private CatalogoItem $reforma;
    private CatalogoItem $verniz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unidade = Unidade::factory()->create(['codigo' => 'ASN']);
        $this->outra = Unidade::factory()->create(['codigo' => 'GYN']);
        $this->dono = $this->usuario(Papel::Proprietario);
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->gerente2 = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
        $this->ordem = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana Costa', 'telefone' => '61991234567', 'placa' => 'ABC1D23', 'motivo' => 'Rodas']);
        app(Ordens::class)->iniciarDiagnostico($this->ordem, $this->mecanico);
        $c = app(Catalogo::class);
        $this->verniz = $c->salvar($this->dono, ['nome' => 'Verniz protetor', 'categoria' => 'Rodas', 'unidade_venda' => 'roda', 'preco' => '45,00', 'desconto_max' => '15']);
        $this->reforma = $c->salvar($this->dono, ['nome' => 'Reforma geral aro 17', 'categoria' => 'Rodas', 'unidade_venda' => 'roda', 'preco' => '180,00', 'desconto_max' => '10', 'adicionais' => [$this->verniz->id]]);
    }

    private function rascunho(string $preco, int $descontoGeral = 0, ?User $ator = null)
    {
        return app(Orcamentos::class)->salvarRascunho($this->ordem, $ator ?? $this->gerente,
            [['descricao' => 'Reforma geral aro 17', 'quantidade' => '4', 'preco' => $preco, 'catalogo_item_id' => $this->reforma->id]],
            $descontoGeral, 'Cliente fiel');
    }

    public function test_crud_catalogo_com_historico_auditado_e_permissoes(): void
    {
        $this->entrarComo($this->gerente)->get(route('catalogo.index'))->assertOk()->assertSee('Reforma geral aro 17');
        $this->entrarComo($this->gerente)->post(route('catalogo.store'), ['nome' => 'Bateria 60Ah', 'categoria' => 'Baterias', 'unidade_venda' => 'unidade', 'preco' => '520,00'])->assertRedirect();
        $bat = CatalogoItem::where('nome', 'Bateria 60Ah')->firstOrFail();
        $this->assertNull($bat->desconto_max_percentual);
        $this->entrarComo($this->gerente)->put(route('catalogo.update', $bat), ['nome' => 'Bateria 60Ah', 'categoria' => 'Baterias', 'unidade_venda' => 'unidade', 'preco' => '540,00', 'ativo' => '1',
            'precos_unidade' => [$this->unidade->id => '530,00']])->assertRedirect();
        $this->assertSame(54000, $bat->fresh()->preco_centavos);
        $this->assertSame(53000, $bat->fresh()->precoNaUnidade($this->unidade->id));
        $this->assertDatabaseHas('catalogo_precos_historico', ['catalogo_item_id' => $bat->id, 'preco_anterior_centavos' => 52000, 'preco_novo_centavos' => 54000, 'alterado_por' => $this->gerente->id]);
        $this->assertTrue(AuditEvent::where('acao', 'catalogo_item_alterado')->where('entidade_id', $bat->id)->exists());
        // Histórico é somente inserção.
        try {
            DB::transaction(fn () => DB::table('catalogo_precos_historico')->where('catalogo_item_id', $bat->id)->delete()); // savepoint
            $this->fail('Histórico deveria ser imutável.');
        } catch (QueryException) {
        }
        // Gerente não afrouxa o próprio limite nem define preço de outra unidade.
        $this->entrarComo($this->gerente)->put(route('catalogo.update', $this->reforma), ['nome' => 'Reforma geral aro 17', 'categoria' => 'Rodas', 'unidade_venda' => 'roda', 'preco' => '180,00', 'desconto_max' => '50'])->assertForbidden();
        $this->entrarComo($this->gerente)->put(route('catalogo.update', $bat), ['nome' => 'Bateria 60Ah', 'categoria' => 'Baterias', 'unidade_venda' => 'unidade', 'preco' => '540,00', 'precos_unidade' => [$this->outra->id => '1,00']])->assertForbidden();
        // Desativar: some do orçamento.
        $this->entrarComo($this->dono)->put(route('catalogo.update', $bat), ['nome' => 'Bateria 60Ah', 'categoria' => 'Baterias', 'unidade_venda' => 'unidade', 'preco' => '540,00', 'ativo' => '0', 'desconto_max' => '5'])->assertRedirect();
        $this->assertFalse($bat->fresh()->ativo);
        $this->assertSame('5.00', $bat->fresh()->desconto_max_percentual);
        // Recepção e mecânico: sem acesso (mecânico nunca vê preços, mesmo com concessão adulterada).
        $this->entrarComo($this->recepcao)->get(route('catalogo.index'))->assertForbidden();
        $this->mecanico->forceFill(['permissoes_extras' => ['aprovar_desconto', 'gerir_catalogo']])->save();
        $this->entrarComo($this->mecanico)->get(route('catalogo.index'))->assertForbidden();
        $this->entrarComo($this->mecanico)->get(route('catalogo.edit', $bat))->assertForbidden();
        $this->entrarComo($this->mecanico)->get(route('aprovacoes.index'))->assertForbidden();
        $this->assertCount(0, app(AprovacoesInternas::class)->pendentes($this->mecanico));
    }

    public function test_importacao_csv_relata_linhas_recusadas_e_grava_as_validas(): void
    {
        $csv = "nome;categoria;unidade;preço;desconto_max\n"
            ."Diamantação de roda;Rodas;roda;190,00;5\n"
            ."Reforma geral aro 17;Rodas;roda;200,00;\n"          // atualiza preço, mantém limite
            .";Rodas;roda;10,00;5\n"                              // sem nome
            ."Pneu;Pneus;caixa;10,00;5\n"                         // unidade inválida
            ."Bateria 45Ah;Baterias;unidade;abc;5\n"              // preço inválido
            ."Polimento;Rodas;roda;90,00;150\n"                   // desconto inválido
            ."Diamantação de roda;Rodas;roda;195,00;5\n"          // repetido no arquivo
            ."Linha;quebrada\n";
        $this->entrarComo($this->dono)->post(route('catalogo.importar'), ['arquivo' => UploadedFile::fake()->createWithContent('tabela.csv', $csv)])
            ->assertRedirect(route('catalogo.index'));
        $r = session('relatorio_importacao');
        $this->assertSame(1, $r['criados']);
        $this->assertSame(1, $r['atualizados']);
        $this->assertSame([4, 5, 6, 7, 8, 9], array_column($r['recusadas'], 'linha'));
        $this->assertSame(20000, $this->reforma->fresh()->preco_centavos);
        $this->assertSame('10.00', $this->reforma->fresh()->desconto_max_percentual);
        $this->assertDatabaseHas('catalogo_precos_historico', ['catalogo_item_id' => $this->reforma->id, 'origem' => 'importacao', 'preco_novo_centavos' => 20000]);
        $this->assertTrue(AuditEvent::where('acao', 'catalogo_importado')->exists());
        $this->entrarComo($this->dono)->get(route('catalogo.index'))->assertSee('linha(s) recusada(s)', false)->assertSee('caixa');

        // Gerente sem permissão de limite: linha com desconto_max é recusada; sem desconto é aceita.
        $r2 = app(Catalogo::class)->importarCsv($this->gerente, "Balanceamento;Rodas;roda;25,00;20\nAlinhamento;Mecânica;hora;80,00;");
        $this->assertSame(1, $r2['criados']);
        $this->assertStringContainsString('Sem permissão', $r2['recusadas'][0]['motivos'][0]);
    }

    public function test_item_do_catalogo_usa_preco_de_tabela_do_servidor_e_congela_no_envio(): void
    {
        app(Catalogo::class)->salvar($this->dono, ['nome' => 'Reforma geral aro 17', 'categoria' => 'Rodas', 'unidade_venda' => 'roda', 'preco' => '180,00', 'precos_unidade' => [$this->unidade->id => '170,00']], $this->reforma);
        // Preço em branco: assume a tabela da unidade; item livre convive.
        $v1 = app(Orcamentos::class)->salvarRascunho($this->ordem, $this->gerente, [
            ['descricao' => 'Reforma geral aro 17', 'quantidade' => '4', 'preco' => '', 'catalogo_item_id' => $this->reforma->id],
            ['descricao' => 'Troca de válvula', 'quantidade' => '4', 'preco' => '10,00'],
        ]);
        $item = $v1->itens->first();
        $this->assertSame($this->reforma->id, $item->catalogo_item_id);
        $this->assertSame(17000, (int) $item->preco_tabela_centavos);
        $this->assertSame(17000, (int) $item->preco_unitario_centavos);
        $this->assertNull($v1->itens->last()->catalogo_item_id);
        app(Orcamentos::class)->enviar($v1, $this->gerente);
        app(Catalogo::class)->salvar($this->dono, ['nome' => 'Reforma geral aro 17', 'categoria' => 'Rodas', 'unidade_venda' => 'roda', 'preco' => '250,00', 'precos_unidade' => [$this->unidade->id => '']], $this->reforma);
        $this->assertSame(17000, (int) $item->fresh()->preco_tabela_centavos);
        $this->expectException(QueryException::class);
        DB::table('orcamento_itens')->where('id', $item->id)->update(['preco_tabela_centavos' => 25000]);
    }

    public function test_desconto_dentro_do_limite_envia_direto(): void
    {
        $v = $this->rascunho('162,00'); // exatamente 10%
        $this->assertSame(0, AprovacaoInterna::count());
        app(Orcamentos::class)->enviar($v, $this->gerente);
        $this->assertSame('enviado', $v->fresh()->status);
        // Desconto geral rateado também conta: 5% no item + 5% geral = 9,75% → ainda dentro.
        $this->assertFalse(AprovacoesInternas::excede(17100, 18000, '10.00', 68400, 3420));
        $this->assertTrue(AprovacoesInternas::excede(17100, 18000, '10.00', 68400, 3800)); // ≈10,28%
    }

    public function test_acima_do_limite_bloqueia_envio_ate_aprovacao_e_autoaprovacao_e_proibida(): void
    {
        $v = $this->rascunho('150,00'); // 16,67% > 10%
        $pedido = AprovacaoInterna::sole();
        $this->assertSame('pendente', $pedido->status);
        $this->assertSame('16.67', $pedido->maior_desconto_percentual);
        try {
            app(Orcamentos::class)->enviar($v, $this->gerente);
            $this->fail('Envio deveria estar bloqueado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('aprovação interna', $e->getMessage());
        }
        $this->entrarComo($this->gerente)->post(route('orcamentos.enviar', [$this->ordem, $v]))->assertSessionHasErrors('itens');
        $this->assertSame('rascunho', $v->fresh()->status);

        // Quem pediu não aprova o próprio pedido, mesmo com a permissão concedida.
        $this->gerente->forceFill(['permissoes_extras' => ['aprovar_desconto']])->save();
        $this->entrarComo($this->gerente)->post(route('aprovacoes.decidir', $pedido), ['decisao' => 'aprovada', 'justificativa' => 'Eu mesmo aprovo'])->assertForbidden();
        $this->assertCount(0, app(AprovacoesInternas::class)->pendentes($this->gerente));
        // Gerente sem a permissão: 403. Aprovador de outra unidade: 403.
        $this->entrarComo($this->gerente2)->post(route('aprovacoes.decidir', $pedido), ['decisao' => 'aprovada', 'justificativa' => 'Pode sim'])->assertForbidden();
        $gerenteOutra = $this->usuario(Papel::Gerente, $this->outra);
        $gerenteOutra->forceFill(['permissoes_extras' => ['aprovar_desconto']])->save();
        $this->entrarComo($gerenteOutra)->post(route('aprovacoes.decidir', $pedido), ['decisao' => 'aprovada', 'justificativa' => 'Pode sim'])->assertForbidden();
        $this->assertCount(0, app(AprovacoesInternas::class)->pendentes($gerenteOutra));

        // Gerente 2 com a permissão concedida (como o supervisor) vê no sino/lista e aprova com justificativa.
        $this->gerente2->forceFill(['permissoes_extras' => ['aprovar_desconto']])->save();
        $this->assertSame([$pedido->id], app(AprovacoesInternas::class)->pendentes($this->gerente2)->pluck('id')->all());
        $this->entrarComo($this->gerente2)->get(route('aprovacoes.index'))->assertOk()->assertSee('16,67%');
        $this->entrarComo($this->gerente2)->post(route('aprovacoes.decidir', $pedido), ['decisao' => 'aprovada', 'justificativa' => ''])->assertSessionHasErrors('justificativa');
        $this->entrarComo($this->gerente2)->post(route('aprovacoes.decidir', $pedido), ['decisao' => 'aprovada', 'justificativa' => 'Cliente de frota, volume alto'])->assertRedirect();
        $this->assertSame('aprovada', $pedido->fresh()->status);
        $this->assertTrue(AuditEvent::where('acao', 'desconto_aprovado')->where('entidade_id', $pedido->id)->where('ator_id', $this->gerente2->id)->exists());
        $this->assertCount(0, app(AprovacoesInternas::class)->pendentes($this->dono));

        // Re-salvar sem mudar mantém a aprovação; o envio passa.
        $v = $this->rascunho('150,00');
        $this->assertSame('aprovada', $pedido->fresh()->status);
        app(Orcamentos::class)->enviar($v, $this->gerente);
        $this->assertSame('enviado', $v->fresh()->status);
    }

    public function test_alteracao_apos_aprovacao_exige_novo_pedido_e_proprietario_pode_aprovar_o_proprio(): void
    {
        $this->rascunho('150,00', ator: $this->dono);
        $p1 = AprovacaoInterna::sole();
        app(AprovacoesInternas::class)->decidir($p1, $this->dono, 'aprovada', 'Política comercial do mês');
        $this->assertSame('aprovada', $p1->fresh()->status);
        $v = $this->rascunho('140,00'); // mudou: aprovação anterior deixa de valer
        $this->assertSame('cancelada', $p1->fresh()->status);
        $this->assertSame('pendente', AprovacaoInterna::latest('id')->first()->status);
        $this->expectException(ValidationException::class);
        app(Orcamentos::class)->enviar($v, $this->gerente);
    }

    public function test_recusa_mantem_bloqueio_e_ajuste_dentro_do_limite_libera(): void
    {
        $v = $this->rascunho('150,00');
        $pedido = AprovacaoInterna::sole();
        $this->entrarComo($this->dono)->post(route('aprovacoes.decidir', $pedido), ['decisao' => 'recusada', 'justificativa' => 'Margem insuficiente'])->assertRedirect();
        $this->assertSame('recusada', $pedido->fresh()->status);
        $this->assertTrue(AuditEvent::where('acao', 'desconto_recusado')->exists());
        // Mesmo conteúdo continua recusado (não abre pedido novo).
        $this->rascunho('150,00');
        $this->assertSame(1, AprovacaoInterna::count());
        try {
            app(Orcamentos::class)->enviar($v->fresh(), $this->gerente);
            $this->fail('Recusado não pode ser enviado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('recusado', $e->getMessage());
        }
        // Já decidido não decide de novo.
        try {
            app(AprovacoesInternas::class)->decidir($pedido, $this->dono, 'aprovada', 'Mudei de ideia');
            $this->fail('Pedido decidido não aceita nova decisão.');
        } catch (ValidationException) {
        }
        $v = $this->rascunho('165,00'); // 8,33%: dentro do limite
        app(Orcamentos::class)->enviar($v, $this->gerente);
        $this->assertSame('enviado', $v->fresh()->status);
    }

    public function test_item_livre_segue_regra_geral_e_desconto_em_item_exige_permissao_e_motivo(): void
    {
        config(['oficina.desconto_max_geral_percentual' => '10']);
        $s = app(Orcamentos::class);
        $s->salvarRascunho($this->ordem, $this->gerente, [['descricao' => 'Serviço avulso', 'quantidade' => '1', 'preco' => '100,00']], 1000, 'Cliente fiel');
        $this->assertSame(0, AprovacaoInterna::count());
        $s->salvarRascunho($this->ordem, $this->gerente, [['descricao' => 'Serviço avulso', 'quantidade' => '1', 'preco' => '100,00']], 1100, 'Cliente fiel');
        $this->assertSame(1, AprovacaoInterna::where('status', 'pendente')->count());

        $this->expectException(ValidationException::class);
        $s->salvarRascunho($this->ordem, $this->gerente, [['descricao' => 'Reforma', 'quantidade' => '1', 'preco' => '170,00', 'catalogo_item_id' => $this->reforma->id]]);
    }

    public function test_editor_inclui_item_do_catalogo_com_desconto_percentual_e_sugere_adicionais(): void
    {
        $this->actingAs($this->gerente);
        \Livewire\Livewire::test(\App\Livewire\OrcamentoEditor::class, ['ordem' => $this->ordem])
            ->set('catalogoEscolhido', (string) $this->reforma->id)
            ->call('incluirCatalogo')
            ->assertSet('itens.0.catalogo_item_id', $this->reforma->id)
            ->assertSet('itens.0.preco', '180,00')
            ->assertSee('Adicionais sugeridos')->assertSee('Verniz protetor')
            ->set('itens.0.desconto', '12,5')
            ->assertSet('itens.0.preco', '157,50')
            ->assertSee('acima do limite')
            ->call('incluirCatalogo', $this->verniz->id)
            ->assertSet('itens.1.catalogo_item_id', $this->verniz->id)
            ->assertDontSee('Adicionais sugeridos')
            ->set('desconto_motivo', 'Cliente fiel')
            ->call('salvar');
        $item = \App\Models\OrcamentoItem::where('catalogo_item_id', $this->reforma->id)->sole();
        $this->assertSame(15750, (int) $item->preco_unitario_centavos);
        $this->assertSame(18000, (int) $item->preco_tabela_centavos);
        $this->assertSame('pendente', AprovacaoInterna::sole()->status);
        $this->get(route('ordens.show', $this->ordem))->assertSee('Aguardando aprovação interna')->assertDontSee('Enviar versão 1 ao cliente');
    }

    public function test_mecanico_nao_ve_precos_do_catalogo_na_ordem(): void
    {
        $v = $this->rascunho('162,00');
        app(Orcamentos::class)->enviar($v, $this->gerente);
        app(Orcamentos::class)->decidirPresencial($v->fresh(), $this->gerente, 'aprovado', 'Aprovou por telefone');
        $this->entrarComo($this->mecanico)->get(route('ordens.show', $this->ordem))->assertOk()
            ->assertSee('Reforma geral aro 17')->assertDontSee('R$');
    }
}
