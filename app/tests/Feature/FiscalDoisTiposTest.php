<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\FiscalDocument;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Services\Catalogo;
use App\Services\Entradas;
use App\Services\Fiscal;
use App\Services\Orcamentos;
use App\Services\Ordens;
use App\Support\DadosFiscais;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Dois tipos de nota (09/11/2026): serviços → NFS-e; peças/produtos → NF-e. Soma das notas = total aprovado. */
class FiscalDoisTiposTest extends TestCase
{
    private Unidade $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado', 'oficina.fiscal.simular_indisponivel' => false]);
        Storage::fake('local');
        $this->unidade = Unidade::factory()->create();
    }

    private function aprovada(array $itens, int $desconto = 0): Ordem
    {
        $rec = $this->usuario(Papel::Recepcao, $this->unidade);
        $ger = $this->usuario(Papel::Gerente, $this->unidade);
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana Costa', 'telefone' => '61991234567', 'placa' => 'ABC1D23', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->usuario(Papel::Mecanico, $this->unidade));
        $v = app(Orcamentos::class)->salvarRascunho($o, $ger, $itens, $desconto, $desconto ? 'Cliente antigo' : null);
        app(Orcamentos::class)->aprovarDireto($v, $ger);

        return $o->fresh();
    }

    public function test_os_mista_gera_nfse_e_nfe_com_desconto_rateado(): void
    {
        $dono = $this->usuario(Papel::Proprietario);
        $roda = app(Catalogo::class)->salvar($dono, ['nome' => 'Roda aro 17', 'categoria' => 'Rodas', 'unidade_venda' => 'unidade', 'preco' => '200,00', 'tipo' => 'produto', 'ncm' => '8708.70.90']);
        $this->assertSame(['produto', '87087090'], [$roda->tipo, $roda->ncm]);
        $o = $this->aprovada([
            ['descricao' => 'Alinhamento', 'quantidade' => '1', 'preco' => '300'],                              // livre: serviço (padrão)
            ['descricao' => 'Roda aro 17', 'quantidade' => '1', 'preco' => '', 'catalogo_item_id' => $roda->id, 'tipo' => 'servico'], // catálogo manda: produto
            ['descricao' => 'Válvula', 'quantidade' => '2', 'preco' => '10', 'tipo' => 'produto'],              // livre marcado como peça
        ], 5000);
        $this->assertSame(['servico', 'produto', 'produto'], $o->orcamentoAprovado->itens->pluck('tipo')->all());

        $this->entrarComo($this->usuario(Papel::Recepcao, $this->unidade))->get("/ordens/{$o->numero}?aba=documentos")
            ->assertSee('Emitir as 2 notas fiscais')->assertSee('NFS-e (serviços) de R$ 271,15')->assertSee('NF-e (peças/produtos) de R$ 198,85')->assertSee('Observação na nota (opcional)');
        $this->post(route('fiscal.solicitar', $o), ['observacao' => str_repeat('x', 501)])->assertSessionHasErrors('observacao');
        $this->post(route('fiscal.solicitar', $o), ['observacao' => 'Pedido 123'])->assertRedirect()->assertSessionHas('sucesso');

        $docs = FiscalDocument::where('ordem_id', $o->id)->get()->keyBy('tipo');
        $this->assertSame(['Pedido 123', 'Pedido 123'], [$docs['nfse']->observacao, $docs['nfe']->observacao]);
        $this->get("/ordens/{$o->numero}?aba=documentos")->assertSee('Observação na nota: Pedido 123');
        $this->assertSame([27115, 19885], [$docs['nfse']->valor_centavos, $docs['nfe']->valor_centavos]); // R$ 50 rateado: 28,85 no serviço, 21,15 nas peças
        $this->assertSame($o->orcamentoAprovado->total_centavos, $docs->sum('valor_centavos'), 'soma das notas = total aprovado');
        $this->assertSame(['autorizado', 'autorizado'], [$docs['nfse']->status, $docs['nfe']->status]);
        $this->assertStringStartsWith('SIM-NFE-', $docs['nfe']->numero);
        $this->assertStringContainsString('Nota de venda de peças (NF-e)', Storage::disk('local')->get($docs['nfe']->arquivo));
        $this->assertStringContainsString('Nota de serviço (NFS-e)', Storage::disk('local')->get($docs['nfse']->arquivo));

        // As duas já ativas: não emite de novo.
        $this->post(route('fiscal.solicitar', $o))->assertSessionHasErrors('fiscal');
        $this->assertSame(2, FiscalDocument::where('ordem_id', $o->id)->count());
    }

    public function test_rejeitada_refaz_so_a_mesma_e_so_servico_gera_uma_nota(): void
    {
        $o = $this->aprovada([['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '100,13'], ['descricao' => 'Peça', 'quantidade' => '1', 'preco' => '50', 'tipo' => 'produto']]);
        $ger = $this->usuario(Papel::Gerente, $this->unidade);
        $notas = app(Fiscal::class)->solicitar($o, $ger);
        $this->assertSame(['nfse', 'nfe'], $notas->pluck('tipo')->all());
        $nfse = FiscalDocument::where('ordem_id', $o->id)->where('tipo', 'nfse')->sole();
        $this->assertSame('rejeitado', $nfse->status); // simulador: valor terminado em 13 centavos
        $nova = app(Fiscal::class)->reprocessar($nfse, $ger);
        $this->assertSame('nfse', $nova->tipo);
        $this->assertSame(1, FiscalDocument::where('ordem_id', $o->id)->where('tipo', 'nfe')->count(), 'a NF-e não é duplicada');

        $so = $this->aprovada([['descricao' => 'Revisão', 'quantidade' => '1', 'preco' => '150']]);
        $this->assertSame(['nfse'], app(Fiscal::class)->solicitar($so, $ger)->pluck('tipo')->all());
    }

    public function test_partes_rateio_exato(): void
    {
        // 700 + 350 com R$ 50 de desconto: 33,33 vai para o serviço, o resto para a peça; a soma fecha no centavo.
        $this->assertSame(['nfse' => 66667, 'nfe' => 33333], DadosFiscais::partes([['tipo' => 'servico', 'total_centavos' => 70000], ['tipo' => 'produto', 'total_centavos' => 35000]], 5000));
        $this->assertSame(['nfse' => 0, 'nfe' => 9000], DadosFiscais::partes([['tipo' => 'produto', 'total_centavos' => 10000]], 1000));
    }

    public function test_pagina_dados_fiscais_vem_com_padrao_do_sistema_antigo(): void
    {
        $dono = $this->usuario(Papel::Proprietario);
        \App\Models\Empresa::create(['razao_social' => 'Oficina Ltda', 'nome_fantasia' => 'Oficina']);
        $this->entrarComo($dono)->get(route('fiscal.config.edit'))->assertOk()->assertSee('140101')->assertSee('5102')->assertSee('87087090')
            ->assertSee('Peça ao contador para conferir');
        $this->put(route('fiscal.config.update'), ['nfse_aliquota_iss' => '3,5', 'inscricao_municipal' => '07.123.456/001-89'] + DadosFiscais::PADRAO)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['3.50', '0712345600189'], [DadosFiscais::atuais()['nfse_aliquota_iss'], DadosFiscais::atuais()['inscricao_municipal']]);
        $this->put(route('fiscal.config.update'), ['nfe_cfop_dentro' => '6102'] + DadosFiscais::PADRAO)->assertSessionHasErrors('nfe_cfop_dentro');
        $this->entrarComo($this->usuario(Papel::Recepcao, $this->unidade))->get(route('fiscal.config.edit'))->assertForbidden();
    }
}
