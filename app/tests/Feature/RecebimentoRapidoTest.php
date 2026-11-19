<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Cliente;
use App\Models\FiscalDocument;
use App\Models\NotaAvulsa;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 19/11/2026: cliente volta para pagar → busca e "Receber" na própria lista A receber do caixa; Mercado Livre separado no painel. */
class RecebimentoRapidoTest extends TestCase
{
    private Unidade $u;
    private User $rec;
    private User $ger;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado']);
        Storage::fake('local');
        $this->u = Unidade::factory()->create();
        $this->rec = $this->usuario(Papel::Recepcao, $this->u);
        $this->ger = $this->usuario(Papel::Gerente, $this->u);
    }

    private function aprovada(string $nome, string $placa, string $preco): Ordem
    {
        $o = app(Entradas::class)->registrar($this->rec, ['unidade_id' => $this->u->id, 'nome' => $nome, 'telefone' => '619'.random_int(10000000, 99999999), 'placa' => $placa, 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->usuario(Papel::Mecanico, $this->u));
        app(Orcamentos::class)->aprovarDireto(app(Orcamentos::class)->salvarRascunho($o, $this->ger, [['descricao' => 'Reforma de roda', 'quantidade' => '1', 'preco' => $preco]]), $this->ger);

        return $o->fresh();
    }

    public function test_busca_e_recebe_na_propria_lista_a_receber(): void
    {
        $joao = $this->aprovada('João Pereira', 'ABC1D23', '500');
        $this->aprovada('Maria Souza', 'XYZ9A87', '300');
        $url = fn (array $q = []) => route('caixa.index', ['aba' => 'receber', 'unidade' => $this->u->id] + $q);

        $this->entrarComo($this->rec)->get($url())->assertOk()->assertSee($joao->numero)->assertSee('Maria Souza')->assertSee('Receber');
        $this->entrarComo($this->rec)->get($url(['q' => 'joão']))->assertSee($joao->numero)->assertDontSee('Maria Souza')->assertSee('500,00');
        $this->entrarComo($this->rec)->get($url(['q' => 'ABC1D']))->assertSee($joao->numero)->assertDontSee('Maria Souza');
        $this->entrarComo($this->rec)->get($url(['q' => $joao->numero]))->assertSee('João Pereira')->assertDontSee('Maria Souza');
        $this->entrarComo($this->rec)->get($url(['q' => 'ninguém']))->assertSee('Nenhuma ordem com saldo encontrada');

        // O formulário da linha usa o mesmo registro da aba Pagamentos da OS; pagamento parcial deixa o saldo.
        $this->entrarComo($this->rec)->from($url(['q' => 'joão']))->post(route('pagamentos.agora', $joao), ['_receber' => $joao->id, 'valor_agora' => '200,00', 'forma_agora' => 'dinheiro'])
            ->assertRedirect($url(['q' => 'joão']))->assertSessionHasNoErrors();
        $this->assertSame(['dinheiro', 20000], [Pagamento::sole()->meio, (int) Pagamento::sole()->valor_centavos]);
        $this->assertSame(30000, app(Financeiro::class)->resumo($joao)['saldo']);
        $this->entrarComo($this->rec)->get($url(['q' => 'joão']))->assertSee('300,00');

        // Mecânico não recebe.
        $this->entrarComo($this->usuario(Papel::Mecanico, $this->u))->post(route('pagamentos.agora', $joao), ['valor_agora' => '10', 'forma_agora' => 'dinheiro'])->assertForbidden();
    }

    public function test_painel_mostra_mercado_livre_separado_do_recebido(): void
    {
        $cli = Cliente::create(['nome' => 'Ingrid', 'telefone' => '98991234567']);
        foreach ([['mercado_livre', 10000, 'autorizado'], ['mercado_livre', 5000, 'autorizado'], ['presencial', 7000, 'autorizado'], ['mercado_livre', 99900, 'rejeitado']] as $k => [$canal, $valor, $status]) {
            $n = NotaAvulsa::create(['unidade_id' => $this->u->id, 'cliente_id' => $cli->id, 'operacao' => 'remessa', 'canal' => $canal, 'itens' => [], 'valor_centavos' => $valor,
                'frete' => 9, 'pagamento' => '01', 'criado_por' => $this->ger->id]);
            FiscalDocument::create(['nota_avulsa_id' => $n->id, 'unidade_id' => $this->u->id, 'tipo' => 'nfe', 'valor_centavos' => $valor, 'status' => $status,
                'provedor' => 'simulado', 'solicitado_por' => $this->ger->id, 'chave_idempotencia' => hash('sha256', "t$k")]);
        }

        $this->entrarComo($this->ger)->get(route('painel'))->assertOk()
            ->assertSee('Mercado Livre (notas avulsas)')->assertSee('R$ 150,00')->assertSee('2 NF-e autorizadas')->assertSee('não entra no Aprovado nem no Recebido (caixa)')
            ->assertSee('Outras notas avulsas')->assertSee('R$ 70,00')->assertDontSee('R$ 1.149,00'); // rejeitada (R$ 999,00, que aparece no sino como pendência) não soma
        $this->entrarComo($this->rec)->get(route('painel'))->assertOk()->assertDontSee('Mercado Livre (notas avulsas)'); // recepção não vê indicadores financeiros
    }
}
