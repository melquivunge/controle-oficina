<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Cliente;
use App\Models\FiscalDocument;
use App\Models\NotaAvulsa;
use App\Models\Unidade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Menu Notas fiscais (19/11/2026): todas as notas, inclusive canceladas, com planilha e XMLs para o contador. */
class NotasFiscaisListaTest extends TestCase
{
    public function test_lista_autorizadas_e_canceladas_filtra_exporta_e_respeita_unidade(): void
    {
        Storage::fake('local');
        [$u, $outra] = Unidade::factory()->count(2)->create();
        $ger = $this->usuario(Papel::Gerente, $u);
        $cli = Cliente::create(['nome' => 'T R C TENORIO LTDA', 'telefone' => '61981322865']);
        $doc = function (Unidade $un, string $status, int $num, string $chave) use ($cli, $ger) {
            $n = NotaAvulsa::create(['unidade_id' => $un->id, 'cliente_id' => $cli->id, 'operacao' => 'remessa', 'canal' => 'internet', 'itens' => [], 'valor_centavos' => 20000,
                'frete' => 9, 'pagamento' => '90', 'criado_por' => $ger->id]);
            Storage::disk('local')->put("fiscal/$num.xml", "<nfe>$num</nfe>");

            return FiscalDocument::create(['nota_avulsa_id' => $n->id, 'unidade_id' => $un->id, 'tipo' => 'nfe', 'valor_centavos' => 20000, 'status' => $status, 'provedor' => 'x',
                'solicitado_por' => $ger->id, 'chave_idempotencia' => hash('sha256', $chave), 'serie' => '1', 'numero_dps' => $num, 'ambiente' => 1, 'xml' => "fiscal/$num.xml",
                'autorizado_em' => now(), 'cancelado_em' => $status === 'cancelado' ? now() : null, 'cancelamento_motivo' => $status === 'cancelado' ? 'Erro no valor da peça' : null]);
        };
        $doc($u, 'autorizado', 9, 'a');
        $doc($u, 'cancelado', 10, 'b');
        $doc($u, 'rejeitado', 11, 'c');
        $doc($outra, 'autorizado', 77, 'd');

        $this->entrarComo($this->usuario(Papel::Mecanico, $u))->get(route('notas-fiscais.index'))->assertForbidden();
        $this->entrarComo($ger)->get(route('notas-fiscais.index'))->assertOk()
            ->assertSee('NF-e nº 9')->assertSee('NF-e nº 10')->assertSee('Cancelado')->assertSee('Erro no valor da peça')
            ->assertDontSee('NF-e nº 11')->assertDontSee('NF-e nº 77'); // rejeitada fora do padrão; outra unidade nunca
        $this->entrarComo($ger)->get(route('notas-fiscais.index', ['situacao' => 'cancelado']))->assertSee('NF-e nº 10')->assertDontSee('NF-e nº 9<');
        $this->entrarComo($ger)->get(route('notas-fiscais.index', ['situacao' => 'rejeitado']))->assertSee('NF-e nº 11');

        $csv = $this->entrarComo($ger)->get(route('notas-fiscais.csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('T R C TENORIO LTDA', $csv);
        $this->assertStringContainsString('Erro no valor da peça', $csv);
        $this->assertStringNotContainsString(';77;', $csv);

        $r = $this->entrarComo($ger)->get(route('notas-fiscais.xmls'))->assertOk();
        $zip = new \ZipArchive;
        $zip->open($r->baseResponse->getFile()->getPathname());
        $nomes = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        sort($nomes);
        $this->assertSame(['NFe-1-000009.xml', 'NFe-1-000010-cancelada.xml'], $nomes);
    }
}
