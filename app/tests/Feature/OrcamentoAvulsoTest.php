<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Livewire\NovaEntrada;
use App\Livewire\OrcamentoEditor;
use App\Models\Cliente;
use App\Models\Orcamento;
use App\Models\OrcamentoAvulso;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Orçamento sem entrada do veículo (10/11/2026): criar, itens, enviar, link do cliente, decisão e virar OS com o mesmo número. */
class OrcamentoAvulsoTest extends TestCase
{
    private Unidade $u;
    private User $gerente;
    private User $recepcao;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado']);
        Storage::fake('local');
        $this->u = Unidade::factory()->create(['codigo' => 'SOF']);
        $this->gerente = $this->usuario(Papel::Gerente, $this->u);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->u);
    }

    private function criar(): OrcamentoAvulso
    {
        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.store'), ['unidade_id' => $this->u->id, 'telefone' => '(61) 99123-4567',
            'nome' => 'Saga Jeep Asa Norte', 'placa' => 'abc-1d23', 'veiculo_descricao' => 'Jeep Compass 2024'])->assertRedirect()->assertSessionHasNoErrors();

        return OrcamentoAvulso::latest('id')->firstOrFail();
    }

    private function comItens(OrcamentoAvulso $o): OrcamentoAvulso
    {
        Livewire::actingAs($this->gerente)->test(OrcamentoEditor::class, ['avulso' => $o])
            ->set('itens', [['descricao' => 'Reforma de roda aro 18', 'quantidade' => '4', 'preco' => '250,00', 'catalogo_item_id' => null, 'desconto' => '', 'tipo' => 'servico']])
            ->call('salvar')->assertHasNoErrors()->assertRedirect(route('orcamentos-avulsos.show', $o));

        return $o->fresh();
    }

    public function test_cria_com_numero_da_sequencia_das_os_e_so_quem_orca(): void
    {
        $this->entrarComo($this->recepcao)->get(route('orcamentos-avulsos.create'))->assertForbidden();
        $this->entrarComo($this->usuario(Papel::Mecanico, $this->u))->post(route('orcamentos-avulsos.store'), ['unidade_id' => $this->u->id])->assertForbidden();

        $o = $this->criar();
        $this->assertSame(['SOF-00001', 'rascunho', 'ABC1D23'], [$o->numero, $o->status, $o->placa]);
        $this->assertSame('Saga Jeep Asa Norte', $o->cliente->nome);
        $this->assertDatabaseHas('audit_events', ['acao' => 'orcamento_avulso_criado', 'entidade_id' => $o->id]);

        // Telefone já cadastrado: usa o cliente existente; o número continua na sequência.
        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.store'), ['unidade_id' => $this->u->id, 'telefone' => '61991234567', 'nome' => 'Outro nome']);
        $this->assertSame(['SOF-00002', 1], [OrcamentoAvulso::latest('id')->value('numero'), Cliente::count()]);

        // Gerente de outra unidade não vê nem altera.
        $outra = Unidade::factory()->create();
        $this->entrarComo($this->usuario(Papel::Gerente, $outra))->get(route('orcamentos-avulsos.show', $o))->assertForbidden();
        $this->entrarComo($this->gerente)->get(route('orcamentos-avulsos.index'))->assertOk()->assertSee('SOF-00001');
        $this->entrarComo($this->gerente)->get(route('orcamentos.index'))->assertSee('Novo orçamento sem entrada');
    }

    public function test_itens_envio_link_do_cliente_decisao_e_cancelamento(): void
    {
        $o = $this->criar();
        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.enviar', $o), ['canal' => 'loja'])->assertSessionHasErrors('itens'); // sem itens
        $o = $this->comItens($o);
        $this->assertSame(100000, $o->total_centavos);

        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.enviar', $o), ['canal' => 'loja'])->assertRedirect()->assertSessionHasNoErrors();
        $o->refresh();
        $this->assertSame('enviado', $o->status);
        $this->assertDatabaseHas('audit_events', ['acao' => 'orcamento_avulso_enviado', 'entidade_id' => $o->id]);

        // Itens congelados depois do envio.
        Livewire::actingAs($this->gerente)->test(OrcamentoEditor::class, ['avulso' => $o])->call('salvar')->assertHasErrors('itens');

        // Abrir no meu WhatsApp: texto com total, validade e link; o link novo substitui o anterior.
        $r = $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.enviar', $o), ['canal' => 'manual']);
        $destino = $r->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/5561991234567?text=', $destino);
        $texto = rawurldecode(substr($destino, strpos($destino, '=') + 1));
        $this->assertStringContainsString('SOF-00001', $texto);
        $this->assertStringContainsString('R$', $texto);
        preg_match('#/q/([A-Za-z0-9]{48})#', $texto, $m);
        $this->get('/q/'.$m[1])->assertOk()->assertSee('Reforma de roda aro 18')->assertSee('Jeep Compass 2024')->assertSee('Não é documento fiscal')
            ->assertHeader('X-Robots-Tag', 'noindex');
        $this->get('/q/'.str_repeat('a', 48))->assertNotFound();

        // Decisão registrada pela equipe.
        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.decidir', $o), ['decisao' => 'aprovado', 'canal' => 'whatsapp', 'observacao' => 'Pode fazer'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['aprovado', 'whatsapp', $this->gerente->id], [$o->fresh()->status, $o->fresh()->decisao_canal, $o->fresh()->decidido_por]);
        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.decidir', $o), ['decisao' => 'recusado', 'canal' => 'telefone'])->assertSessionHasErrors('decisao');
        $this->get('/q/'.$m[1])->assertOk()->assertSee('Orçamento aprovado');

        // Cancelar derruba o link.
        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.cancelar', $o), ['motivo' => 'Cliente desistiu'])->assertSessionHasNoErrors();
        $this->get('/q/'.$m[1])->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['acao' => 'orcamento_avulso_cancelado', 'entidade_id' => $o->id]);
    }

    public function test_vencido_nao_pode_ser_aprovado(): void
    {
        $o = $this->comItens($this->criar());
        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.enviar', $o), ['canal' => 'loja']);
        $o->fresh()->forceFill(['validade' => now()->subDay()->toDateString()])->save();
        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.decidir', $o), ['decisao' => 'aprovado', 'canal' => 'telefone'])->assertSessionHasErrors('decisao');
        $this->assertSame('enviado', $o->fresh()->status);
    }

    public function test_aprovado_vira_os_com_mesmo_numero_e_itens_em_rascunho(): void
    {
        $o = $this->comItens($this->criar());
        // Entrada comum antes: consome o próximo número; o do orçamento continua reservado.
        Livewire::actingAs($this->recepcao)->test(NovaEntrada::class)->set('telefone', '61988887777')->set('placa', 'XYZ9A87')->set('nome', 'Outro')
            ->set('motivo', 'Revisão')->call('salvar')->assertHasNoErrors();
        $this->assertSame('SOF-00002', Ordem::sole()->numero);

        // Ainda não aprovado: o link de entrada não traz o orçamento.
        Livewire::actingAs($this->recepcao)->withQueryParams(['orcamento' => $o->numero])->test(NovaEntrada::class)->assertSet('avulsoId', null);

        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.enviar', $o), ['canal' => 'loja']);
        $this->entrarComo($this->gerente)->post(route('orcamentos-avulsos.decidir', $o), ['decisao' => 'aprovado', 'canal' => 'presencial']);
        $this->entrarComo($this->recepcao)->get(route('orcamentos-avulsos.show', $o))->assertForbidden(); // recepção não orça
        $this->entrarComo($this->gerente)->get(route('orcamentos-avulsos.show', $o))->assertSee('Dar entrada do veículo');

        Livewire::actingAs($this->recepcao)->withQueryParams(['orcamento' => $o->numero])->test(NovaEntrada::class)
            ->assertSet('avulsoId', $o->id)->assertSet('telefone', '61991234567')->assertSet('placa', 'ABC1D23')
            ->set('quilometragem', '1200')->call('salvar')->assertHasNoErrors()->assertRedirect(route('ordens.show', 'SOF-00001'));

        $os = Ordem::where('numero', 'SOF-00001')->sole();
        $this->assertSame([StatusOrdem::Entrada, $o->cliente_id, 1], [$os->status, $os->cliente_id, $os->sequencia]);
        $rascunho = Orcamento::where('ordem_id', $os->id)->sole();
        $this->assertSame(['rascunho', 100000, 1], [$rascunho->status, $rascunho->total_centavos, $rascunho->itens()->count()]);
        $this->assertStringContainsString('SOF-00001', $rascunho->observacao_cliente);
        $this->assertSame(['convertido', $os->id], [$o->fresh()->status, $o->fresh()->ordem_id]);
        $this->assertDatabaseHas('audit_events', ['acao' => 'orcamento_avulso_convertido', 'entidade_id' => $o->id]);

        // Não converte duas vezes; a próxima entrada segue a sequência.
        Livewire::actingAs($this->recepcao)->withQueryParams(['orcamento' => $o->numero])->test(NovaEntrada::class)->assertSet('avulsoId', null);
        Livewire::actingAs($this->recepcao)->test(NovaEntrada::class)->set('telefone', '61977776666')->set('placa', 'JKL2M34')->set('nome', 'Mais um')
            ->set('motivo', 'Revisão')->call('salvar')->assertHasNoErrors();
        $this->assertTrue(Ordem::where('numero', 'SOF-00003')->exists());
    }
}
