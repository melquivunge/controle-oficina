<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Livewire\NovaEntrada;
use App\Models\Cliente;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Veiculo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** OS do sistema antigo abertas ou com saldo → OS nova pela Nova entrada, com os itens em rascunho (11/11/2026). */
class ImportarOsLegadaTest extends TestCase
{
    private Unidade $u;
    private User $rec;
    private Cliente $cli;
    private Veiculo $vei;
    private int $linha = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->u = Unidade::factory()->create(['codigo' => 'SN']);
        $this->rec = $this->usuario(Papel::Recepcao, $this->u);
        $this->cli = Cliente::create(['nome' => 'José da Silva', 'telefone' => '61991234567']);
        $this->vei = new Veiculo(['placa' => 'JIK2116', 'modelo' => 'Gol']);
        $this->vei->cliente_id = $this->cli->id;
        $this->vei->save();
    }

    private function reg(string $tabela, array $dados, ?string $grupo = null, bool $ligado = true): int
    {
        return DB::table('legado_registros')->insertGetId(['origem' => 'sga', 'tabela' => $tabela, 'linha' => ++$this->linha, 'codigo' => $dados['CODIGO'] ?? null,
            'grupo' => $grupo, 'cliente_id' => $ligado ? $this->cli->id : null, 'veiculo_id' => $ligado ? $this->vei->id : null, 'dados' => json_encode($dados)]);
    }

    /** OS com 2 itens: serviço 1 × 300 e peça 3 × 33,33 − 1,00 de desconto (= 98,99, não divide exato por 3): total 398,99. */
    private function os(string $codigo, string $status, string $cancelada = 'N', string $mov = ''): int
    {
        $id = $this->reg('TBL_OS', ['CODIGO' => $codigo, 'STATUS' => $status, 'CANCELADA' => $cancelada, 'PAG' => 'N', 'CHCOD_MOVIMENTO' => $mov, 'KM' => '45200',
            'OBS' => 'Barulho na suspensão', 'DATAIN' => '2026-08-01'], "OS:$codigo");
        $this->reg('TBL_SERVICO', ['CODIGO' => "s$codigo", 'CHOS' => $codigo, 'DESCRICAO' => 'TROCA DE BIELETA', 'QTD' => '1', 'PRECO' => '300', 'DESCONTO' => '0'], "OS:$codigo");
        $this->reg('TBL_PECA', ['CODIGO' => "p$codigo", 'CHOS' => $codigo, 'DESCRICAO' => 'BIELETA', 'QTD' => '3', 'PRECO' => '33.33', 'DESCONTO' => '1.00'], "OS:$codigo");

        return $id;
    }

    private function mov(string $cod, string $parc1, float $valor, string $baixa): void
    {
        $this->reg('TBL_MOVIMENTO', ['COD_MOVIMENTO' => $cod, 'CH_MOV_PARC1' => $parc1, 'VALOR_TOTAL' => (string) $valor, 'BAIXA' => $baixa, 'EXCLUSAO' => 'N'], null, false);
    }

    public function test_so_abertas_ou_com_saldo_tem_botao_e_filtro(): void
    {
        $aberta = $this->os('100', 'A');
        $this->mov('900', '', 199.0, 'S');
        $this->mov('901', '900', 200.0, 'N');
        $comSaldo = $this->os('101', 'F', 'N', '900');
        $this->mov('910', '', 398.99, 'S');
        $quitada = $this->os('102', 'F', 'N', '910');
        $semFinanceiro = $this->os('103', 'F');
        $cancelada = $this->os('104', 'A', 'S');

        $this->entrarComo($this->rec)->get(route('historico.show', $aberta))->assertSee('Importar para nova OS');
        $this->entrarComo($this->rec)->get(route('historico.show', $comSaldo))->assertSee('Importar para nova OS')->assertSee('em aberto R$ 200,00 · pago R$ 199,00');
        foreach ([$quitada, $semFinanceiro, $cancelada] as $id) {
            $this->entrarComo($this->rec)->get(route('historico.show', $id))->assertDontSee('Importar para nova OS');
        }
        $this->entrarComo($this->rec)->get(route('historico.index', ['situacao' => 'importar']))->assertSee('2 OS encontradas')->assertSee('>100<', false)->assertSee('>101<', false)
            ->assertDontSee('>102<', false);
        $this->entrarComo($this->usuario(Papel::Mecanico, $this->u))->get(route('entradas.nova', ['legado' => $aberta]))->assertForbidden();
    }

    public function test_importa_pela_nova_entrada_com_itens_exatos_e_uma_vez_so(): void
    {
        $this->mov('900', '', 199.0, 'S');
        $this->mov('901', '900', 199.99, 'N');
        $id = $this->os('101', 'F', 'N', '900');

        Livewire::actingAs($this->rec)->withQueryParams(['legado' => $id])->test(NovaEntrada::class)
            ->assertSet('legadoId', $id)->assertSet('cliente_id', $this->cli->id)->assertSet('placa', 'JIK2116')->assertSet('quilometragem', '45200')
            ->set('unidade_id', $this->u->id)->call('salvar')->assertHasNoErrors();

        $o = Ordem::sole();
        $this->assertSame([$this->cli->id, $this->vei->id], [$o->cliente_id, $o->veiculo_id]);
        $this->assertStringContainsString('OS nº 101 do sistema antigo', $o->motivo);
        $r = Orcamento::where('ordem_id', $o->id)->sole();
        $this->assertSame(['rascunho', 39899], [$r->status, $r->total_centavos]);
        $this->assertSame(39899, (int) $r->itens()->sum('total_centavos'), 'total igual ao do sistema antigo');
        $this->assertSame(['TROCA DE BIELETA', '3 x BIELETA'], $r->itens()->orderBy('posicao')->pluck('descricao')->all());
        $this->assertSame(['servico', 'produto'], $r->itens()->orderBy('posicao')->pluck('tipo')->all());
        $this->assertStringContainsString('Já pago no sistema antigo: R$ 199,00', $r->observacao_cliente);
        $this->assertSame($o->id, DB::table('legado_registros')->where('id', $id)->value('ordem_id'));
        $this->assertDatabaseHas('audit_events', ['acao' => 'os_legada_importada', 'entidade_id' => $o->id]);

        // Uma vez só: o botão vira link para a OS nova e a Nova entrada não importa de novo.
        $this->entrarComo($this->rec)->get(route('historico.show', $id))->assertDontSee('Importar para nova OS')->assertSee('Ver OS '.$o->numero);
        Livewire::actingAs($this->rec)->withQueryParams(['legado' => $id])->test(NovaEntrada::class)->assertSet('legadoId', null);
        $this->entrarComo($this->rec)->get(route('historico.index', ['situacao' => 'importar']))->assertSee('Nenhuma OS antiga');
    }
}
