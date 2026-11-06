<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

/** Bloco A: colaborador/mecânico nunca recebe preço, valores, pagamentos nem contato do cliente (regra no servidor). */
class VisaoColaboradorTest extends TestCase
{
    private Unidade $unidade;
    private User $gerente;
    private User $recepcao;
    private User $mecanico;
    private Ordem $ordem;

    private const TELEFONE = '61991234567';
    private const ABAS = ['resumo', 'diagnostico', 'orcamento', 'pagamentos', 'documentos', 'historico'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->unidade = Unidade::factory()->create();
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
        $this->ordem = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana Costa', 'telefone' => self::TELEFONE,
            'placa' => 'RDA1B23', 'modelo' => 'Onix', 'motivo' => 'Jogo de rodas — reforma geral aro 17']);
        app(Ordens::class)->iniciarDiagnostico($this->ordem, $this->mecanico);
        app(Ordens::class)->registrarDiagnostico($this->ordem, 'Rodas com empeno leve e pintura descascando.', $this->mecanico);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($this->ordem, $this->gerente, [
            ['descricao' => 'Reforma geral de roda aro 17', 'quantidade' => '4', 'preco' => '250,00'],
            ['descricao' => 'Pintura diamantada', 'quantidade' => '1', 'preco' => '137,45'],
        ], 2000, 'Cliente antigo');
        $s->enviar($v, $this->gerente);
        $s->decidirPresencial($v->fresh(), $this->recepcao, 'aprovado', 'Cliente aprovou por telefone às 10h');
        app(\App\Services\PrazosEntrega::class)->definir($this->ordem->fresh(), $this->gerente, diasUteis: 5);
        // Pagamento parcial: gera auditoria com valores na própria OS.
        app(Financeiro::class)->registrarManual($this->ordem->fresh(), $this->recepcao, 'dinheiro', 50000, 'Sinal de R$ 500,00');
    }

    /** Fragmentos que nunca podem aparecer para o mecânico: moeda, totais, desconto e telefone em qualquer formato. */
    private function assertSemValoresNemContato(\Illuminate\Testing\TestResponse $r, string $onde): void
    {
        foreach (['R$', '1.137,45', '1.117,45', '137,45', '20,00', '500,00', '99123-4567', self::TELEFONE, 'Pagamentos e entrega', 'Cliente antigo'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $r->getContent(), "“{$proibido}” vazou para o mecânico em {$onde}");
        }
    }

    public function test_mecanico_nao_recebe_valores_nem_contato_em_nenhuma_aba_da_os(): void
    {
        foreach (self::ABAS as $aba) {
            $r = $this->entrarComo($this->mecanico)->get("/ordens/{$this->ordem->numero}?aba={$aba}")->assertOk();
            $this->assertSemValoresNemContato($r, "aba {$aba}");
        }
        // O que ele precisa ver: veículo, chegada, serviço aprovado sem preço, diagnóstico, observações e entrega prevista.
        $this->entrarComo($this->mecanico)->get("/ordens/{$this->ordem->numero}")->assertOk()
            ->assertSee($this->ordem->veiculo->placaFormatada())->assertSee('Onix')->assertSee('Jogo de rodas — reforma geral aro 17')
            ->assertSee('4 × Reforma geral de roda aro 17')->assertSee('1 × Pintura diamantada')
            ->assertSee('Rodas com empeno leve')->assertSee('Entrega prevista')
            ->assertSee($this->ordem->fresh()->entrega_prevista_em->format('d/m/Y'));
        // Histórico do mecânico: só eventos operacionais (sem recebimento, orçamento ou link).
        $hist = $this->entrarComo($this->mecanico)->get("/ordens/{$this->ordem->numero}?aba=historico")->assertOk();
        $hist->assertSee('Diagnóstico registrado')->assertSee('Data de entrega prevista definida')
            ->assertDontSee('Recebimento manual registrado')->assertDontSee('Link do cliente gerado');
        $this->assertSame(1, Pagamento::count(), 'cenário precisa ter pagamento para o teste ter valor');
    }

    public function test_mecanico_nao_ve_valores_na_fila_lista_busca_por_telefone_nem_painel(): void
    {
        foreach (['/fila', '/ordens', "/ordens?busca={$this->ordem->numero}"] as $url) {
            $this->assertSemValoresNemContato($this->entrarComo($this->mecanico)->get($url)->assertOk(), $url);
        }
        $this->entrarComo($this->mecanico)->get('/fila')->assertSee($this->ordem->numero)->assertSee('Entrega prevista');
        // Busca por telefone não serve para sondar contato do cliente.
        $this->entrarComo($this->mecanico)->get('/ordens?busca=991234567')->assertOk()->assertDontSee($this->ordem->numero);
        $this->entrarComo($this->recepcao)->get('/ordens?busca=991234567')->assertOk()->assertSee($this->ordem->numero);
        $this->entrarComo($this->mecanico)->get('/')->assertRedirect(route('painel.mecanico'));
    }

    public function test_mecanico_recebe_403_nas_rotas_financeiras_de_orcamento_e_contato(): void
    {
        $o = $this->ordem->numero;
        $p = Pagamento::sole();
        $v = $this->ordem->orcamentoAprovado;
        $m = $this->entrarComo($this->mecanico);
        $m->post("/ordens/{$o}/cobrancas")->assertForbidden();
        $m->post("/ordens/{$o}/recebimentos", ['meio' => 'dinheiro', 'valor_recebido' => '10,00'])->assertForbidden();
        $m->post("/ordens/{$o}/pagamentos/{$p->id}/devolver", ['valor_devolucao' => '10,00', 'motivo' => 'Teste de devolução'])->assertForbidden();
        $m->post("/ordens/{$o}/pagamentos/{$p->id}/aceitar", ['motivo' => 'Teste de aceite'])->assertForbidden();
        $m->post("/ordens/{$o}/entrega")->assertForbidden();
        $m->post("/ordens/{$o}/fiscal")->assertForbidden();
        $m->get('/caixa')->assertForbidden();
        $m->get('/orcamentos')->assertForbidden();
        $m->post("/ordens/{$o}/link")->assertForbidden();
        $m->post("/ordens/{$o}/link/revogar")->assertForbidden();
        $m->post("/ordens/{$o}/orcamentos/{$v->id}/presencial", ['decisao' => 'aprovado', 'observacao' => 'Ligação do cliente'])->assertForbidden();
        $m->post("/ordens/{$o}/prazo-entrega", ['prazo_dias' => 3])->assertForbidden();
        $m->get('/clientes')->assertForbidden();
        $m->get("/clientes/{$this->ordem->cliente_id}")->assertForbidden();
        $m->get('/indicadores')->assertForbidden();
        $m->get('/painel/exportar')->assertForbidden();
        $this->assertSame(1, Pagamento::count());
    }

    public function test_permissoes_de_valor_e_contato_nunca_alcancam_o_mecanico(): void
    {
        foreach (['ver_valores', 'ver_contato_cliente'] as $perm) {
            $this->assertArrayNotHasKey($perm, Papel::PERMISSOES_CONCEDIVEIS);
            $this->assertFalse($this->mecanico->pode($perm));
            $this->assertTrue($this->recepcao->pode($perm));
            $this->assertTrue($this->gerente->pode($perm));
            $this->assertTrue($this->usuario(Papel::Proprietario)->pode($perm));
        }
        // Mesmo que a coluna de concessões seja adulterada, o mecânico continua sem valores/contato.
        $this->mecanico->forceFill(['permissoes_extras' => ['ver_valores', 'ver_contato_cliente']])->save();
        $this->assertFalse($this->mecanico->fresh()->pode('ver_valores'));
        $this->assertSemValoresNemContato($this->entrarComo($this->mecanico->fresh())->get("/ordens/{$this->ordem->numero}")->assertOk(), 'resumo com concessão adulterada');
        // E a tela de usuários recusa conceder.
        $dono = $this->usuario(Papel::Proprietario);
        $this->entrarComo($dono)->put("/configuracoes/usuarios/{$this->mecanico->id}", [
            'name' => $this->mecanico->name, 'email' => $this->mecanico->email, 'papel' => 'mecanico', 'unidade_id' => $this->unidade->id, 'ativo' => 1,
            'permissoes_extras' => ['ver_valores'],
        ])->assertSessionHasErrors('permissoes_extras.0');
    }

    public function test_mecanico_executa_servicos_sem_ver_precos(): void
    {
        $m = $this->entrarComo($this->mecanico);
        $m->post("/ordens/{$this->ordem->numero}/execucao/iniciar")->assertRedirect();
        $item = $this->ordem->fresh()->orcamentoAprovado->itens->first();
        $m->post("/ordens/{$this->ordem->numero}/execucao/concluir-item", ['item' => $item->id])->assertRedirect();
        $this->assertNotNull($item->fresh()->concluido_em);
        $r = $this->entrarComo($this->mecanico)->get("/ordens/{$this->ordem->numero}?aba=orcamento")->assertOk()
            ->assertSee('Marcar como concluído')->assertSee('concluído por '.$this->mecanico->name);
        $this->assertSemValoresNemContato($r, 'execução');
        $this->assertSame(StatusOrdem::EmExecucao, $this->ordem->fresh()->status);
    }

    public function test_recepcao_e_gerente_continuam_vendo_valores_e_contato(): void
    {
        foreach ([$this->recepcao, $this->gerente] as $u) {
            $this->entrarComo($u)->get("/ordens/{$this->ordem->numero}")->assertOk()
                ->assertSee('R$ 1.117,45')->assertSee('(61) 99123-4567')->assertSee('Pagamentos e entrega')->assertSee('Entrega prevista');
            $this->entrarComo($u)->get("/ordens/{$this->ordem->numero}?aba=orcamento")->assertOk()->assertSee('R$ 137,45')->assertSee('Cliente antigo');
            $this->entrarComo($u)->get("/ordens/{$this->ordem->numero}?aba=historico")->assertOk()->assertSee('Recebimento manual registrado');
        }
    }

    public function test_recepcao_nao_orca_mas_acompanha_cliente_e_gerente_orca(): void
    {
        $o = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Paulo', 'telefone' => '61988887777', 'placa' => 'XYZ9A87', 'motivo' => 'Rodas']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->mecanico);
        $s = app(Orcamentos::class);
        try {
            $s->salvarRascunho($o, $this->recepcao, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '100']]);
            $this->fail('recepção não pode orçar');
        } catch (AuthorizationException) {
        }
        $this->assertFalse($this->recepcao->pode('orcar'));
        $this->entrarComo($this->recepcao)->get("/ordens/{$o->numero}?aba=orcamento")->assertOk()->assertDontSee('Montar orçamento')->assertDontSee('orcamento-editor');

        $v = $s->salvarRascunho($o, $this->gerente, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '100']]);
        $this->entrarComo($this->recepcao)->post("/ordens/{$o->numero}/orcamentos/{$v->id}/enviar")->assertForbidden();
        $this->entrarComo($this->gerente)->get("/ordens/{$o->numero}?aba=orcamento")->assertOk()->assertSee('Aprovar orçamento');
        $this->entrarComo($this->gerente)->post("/ordens/{$o->numero}/orcamentos/{$v->id}/enviar", ['prazo_dias' => 4])->assertRedirect()->assertSessionHas('link_cliente');
        $this->assertSame('enviado', $v->fresh()->status);
        $this->assertNotNull($o->fresh()->entrega_prevista_em);

        // Recepção acompanha: link novo e decisão informada pelo cliente; mas não define prazo.
        $this->entrarComo($this->recepcao)->post("/ordens/{$o->numero}/link")->assertRedirect()->assertSessionHas('link_cliente');
        $this->entrarComo($this->recepcao)->post("/ordens/{$o->numero}/orcamentos/{$v->id}/presencial", ['decisao' => 'aprovado', 'observacao' => 'Cliente ligou às 11h', 'prazo_dias' => 2])->assertForbidden();
        $this->assertSame('enviado', $v->fresh()->status, 'prazo sem permissão é recusado antes de registrar a decisão');
        $this->entrarComo($this->recepcao)->post("/ordens/{$o->numero}/orcamentos/{$v->id}/presencial", ['decisao' => 'aprovado', 'observacao' => 'Cliente ligou às 11h'])->assertRedirect();
        $this->assertSame('aprovado', $v->fresh()->status);
        $this->entrarComo($this->recepcao)->get("/ordens/{$o->numero}")->assertOk()->assertSee('R$ 100,00')->assertSee($o->fresh()->entrega_prevista_em->format('d/m/Y'));
    }
}
