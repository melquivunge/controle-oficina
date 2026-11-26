<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Integracoes\Fiscal\NfeSefaz;
use App\Models\CatalogoItem;
use App\Models\CertificadoDigital;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\FiscalDocument;
use App\Models\NotaAvulsa;
use App\Models\Unidade;
use App\Services\Fiscal;
use App\Support\DadosFiscais;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NFePHP\NFe\Tools;
use Tests\TestCase;

/** Nota de peças avulsa (19/11/2026): NF-e de venda ou remessa sem OS, como a NF-e 998 (remessa CFOP 6949, CSOSN 400, cliente no MA). */
class NotaAvulsaTest extends TestCase
{
    private const CNPJ = '11222333000181';

    private Unidade $u;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Empresa::create(['razao_social' => 'Oficina Teste Ltda', 'nome_fantasia' => 'Oficina Teste', 'cnpj' => self::CNPJ, 'telefone' => '6133334444',
            'cep' => '70040010', 'logradouro' => 'Rua das Rodas', 'numero' => '17', 'bairro' => 'Asa Sul', 'cidade' => 'Brasília', 'uf' => 'DF']);
        $this->u = Unidade::factory()->create();
    }

    private function dadosXml(NotaAvulsa $n, array $fiscal, array $local, int $valor = 10000): array
    {
        return ['tipo' => 'nfe', 'chave' => str_repeat('a', 64), 'fiscal' => $fiscal, 'serie' => '1', 'numero_dps' => 21, 'criado_em' => now(), 'valor_centavos' => $valor,
            'ordem' => 'NA-00001', 'referencia' => 'Nota avulsa NA-00001', 'nfe' => Fiscal::parametrosAvulsa($n, $fiscal), 'observacao' => null,
            'cliente' => 'Ingrid Garcia', 'cliente_documento' => '52998224725', 'cliente_email' => null, 'cliente_local' => $local,
            'itens' => [['descricao' => 'Borda Reforçada', 'quantidade' => '2', 'total_centavos' => $valor, 'ncm' => '87087090', 'codigo' => 3]]];
    }

    /** Valida no XSD oficial (signNFe recusa XML fora do schema) com o certificado fictício de teste. */
    private function assinar(array $dados): string
    {
        $this->entrarComo($this->usuario(Papel::Proprietario))->post(route('certificado.store'), [
            'certificado' => new UploadedFile(base_path('tests/fixtures/certificado-teste.pfx'), 'c.pfx', 'application/x-pkcs12', null, true), 'senha' => 'senha-teste',
        ])->assertSessionHasNoErrors();
        $tools = new Tools(json_encode(['atualizacao' => '2026-11-19 00:00:00', 'tpAmb' => 1, 'razaosocial' => 'Oficina Teste Ltda', 'siglaUF' => 'DF',
            'cnpj' => self::CNPJ, 'schemes' => 'PL_010_V1.30', 'versao' => '4.00']), CertificadoDigital::atual()->certificado());

        return $tools->signNFe((new NfeSefaz(1))->xml($dados));
    }

    public function test_remessa_pelo_mercado_livre_para_outro_estado_igual_a_nota_998(): void
    {
        Empresa::atual()->forceFill(['fiscal' => ['inscricao_estadual' => '0782314200109', 'nfe_intermediador_conta' => 'VILMARRODAS']])->save();
        $n = new NotaAvulsa(['operacao' => 'remessa', 'canal' => 'mercado_livre', 'frete' => 9, 'pagamento' => '01']);
        $xml = $this->assinar($this->dadosXml($n, DadosFiscais::atuais(),
            ['logradouro' => 'Rua Sete Quadra 54 Casa', 'numero' => '15', 'complemento' => '', 'bairro' => 'São Raimundo', 'cidade' => 'São Luís', 'uf' => 'MA', 'cep' => '65057787']));

        foreach (['<natOp>REMESSA DE MERCADORIA</natOp>', '<idDest>2</idDest>', '<CFOP>6949</CFOP>', '<CSOSN>400</CSOSN>', '<NCM>87087090</NCM>', '<UF>MA</UF>',
            '<indPres>2</indPres>', '<indIntermed>1</indIntermed>', '<infIntermed><CNPJ>03007331000141</CNPJ><idCadIntTran>VILMARRODAS</idCadIntTran></infIntermed>',
            '<modFrete>9</modFrete>', '<tPag>01</tPag>', '<vPag>100.00</vPag>', '<vNF>100.00</vNF>', '<infCpl>Nota avulsa NA-00001.'] as $t) {
            $this->assertStringContainsString($t, $xml, $t);
        }
    }

    public function test_venda_na_loja_no_df_e_remessa_sem_pagamento(): void
    {
        Empresa::atual()->forceFill(['fiscal' => ['inscricao_estadual' => '0782314200109']])->save();
        $df = ['logradouro' => 'QNN 10', 'numero' => '1', 'complemento' => '', 'bairro' => 'Ceilândia', 'cidade' => 'Brasília', 'uf' => 'DF', 'cep' => '72220101'];
        $xml = $this->assinar($this->dadosXml(new NotaAvulsa(['operacao' => 'venda', 'canal' => 'presencial', 'frete' => 9, 'pagamento' => '17']), DadosFiscais::atuais(), $df));
        foreach (['<natOp>VENDA DE MERCADORIA</natOp>', '<CFOP>5102</CFOP>', '<CSOSN>102</CSOSN>', '<indPres>1</indPres>', '<tPag>17</tPag>'] as $t) {
            $this->assertStringContainsString($t, $xml, $t);
        }
        $this->assertStringNotContainsString('indIntermed', $xml);

        // Remessa sem pagamento (ex.: garantia) e frete por conta de quem recebe; venda pela internet sem intermediador.
        $xml2 = (new NfeSefaz(1))->xml($this->dadosXml(new NotaAvulsa(['operacao' => 'remessa', 'canal' => 'internet', 'frete' => 1, 'pagamento' => '90']), DadosFiscais::atuais(), $df));
        foreach (['<CFOP>5949</CFOP>', '<tPag>90</tPag>', '<vPag>0.00</vPag>', '<modFrete>1</modFrete>', '<indPres>2</indPres>', '<indIntermed>0</indIntermed>'] as $t) {
            $this->assertStringContainsString($t, $xml2, $t);
        }
    }

    public function test_tela_emite_nota_simulada_com_permissao_e_unidade(): void
    {
        config(['oficina.integracoes' => 'simulado']);
        $rec = $this->usuario(Papel::Recepcao, $this->u);
        $cli = Cliente::create(['nome' => 'Ingrid Garcia', 'telefone' => '98991234567', 'documento' => '52998224725', 'logradouro' => 'Rua Sete', 'numero' => '15',
            'bairro' => 'São Raimundo', 'cidade' => 'São Luís', 'uf' => 'MA', 'cep' => '65057787']);
        $borda = CatalogoItem::create(['nome' => 'Borda Reforçada', 'categoria' => 'Peças', 'unidade_venda' => 'unidade', 'preco_centavos' => 5000, 'tipo' => 'produto', 'ncm' => '87087090']);
        $dados = ['cliente_id' => $cli->id, 'unidade_id' => $this->u->id, 'operacao' => 'remessa', 'canal' => 'mercado_livre', 'frete' => 9, 'pagamento' => '01',
            'observacao' => 'Pedido ML 2000123, Sedex', 'itens' => [['catalogo_item_id' => $borda->id, 'quantidade' => '2'], ['descricao' => '', 'preco' => '']]];

        $this->entrarComo($this->usuario(Papel::Mecanico, $this->u))->get(route('notas-avulsas.index'))->assertForbidden();
        $this->entrarComo($rec)->get(route('notas-avulsas.create', ['q' => 'Ingrid']))->assertOk()->assertSee('Ingrid Garcia');
        $this->entrarComo($rec)->get(route('notas-avulsas.create', ['cliente' => $cli->id]))->assertOk()->assertSee('Emitir NF-e')->assertSee('CFOP 5949 / 6949');

        // Mercado Livre exige a conta do vendedor (intermediador) em Dados fiscais.
        $this->entrarComo($rec)->post(route('notas-avulsas.store'), $dados)->assertSessionHasErrors('canal');
        Empresa::atual()->forceFill(['fiscal' => ['nfe_intermediador_conta' => 'VILMARRODAS']])->save();

        $r = $this->entrarComo($rec)->post(route('notas-avulsas.store'), $dados)->assertSessionHasNoErrors();
        $n = NotaAvulsa::sole();
        $r->assertRedirect(route('notas-avulsas.show', $n));
        $this->assertSame(['remessa', 10000, 'Borda Reforçada', '87087090'], [$n->operacao, $n->valor_centavos, $n->itens[0]['descricao'], $n->itens[0]['ncm']]);
        $doc = FiscalDocument::sole();
        $this->assertSame([$n->id, null, 'nfe', 'autorizado'], [$doc->nota_avulsa_id, $doc->ordem_id, $doc->tipo, $doc->status]);
        $this->assertDatabaseHas('audit_events', ['acao' => 'fiscal_solicitado', 'entidade_id' => $n->id]);
        $this->entrarComo($rec)->get(route('notas-avulsas.show', $n))->assertOk()->assertSee('Nota avulsa NA-00001')->assertSee('Autorizado');
        $this->assertStringContainsString('Nota avulsa NA-00001', $this->entrarComo($rec)->get(route('notas-avulsas.baixar', [$n, $doc]))->assertOk()->streamedContent());
        $this->entrarComo($rec)->get(route('notas-avulsas.index'))->assertSee('NA-00001')->assertSee('Remessa');

        // Outra unidade não vê; recepção não cancela (exige gerente); gerente cancela.
        $this->entrarComo($this->usuario(Papel::Recepcao, Unidade::factory()->create()))->get(route('notas-avulsas.show', $n))->assertForbidden();
        $this->entrarComo($rec)->post(route('notas-avulsas.cancelar', [$n, $doc]), ['motivo' => 'Cliente desistiu da compra no Mercado Livre'])->assertForbidden();
        $this->entrarComo($this->usuario(Papel::Gerente, $this->u))->post(route('notas-avulsas.cancelar', [$n, $doc]), ['motivo' => 'Cliente desistiu da compra no Mercado Livre'])
            ->assertSessionHasNoErrors();
        $this->assertSame('cancelado', $doc->fresh()->status);
    }

    public function test_banco_exige_os_ou_nota_avulsa(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        FiscalDocument::create(['unidade_id' => $this->u->id, 'tipo' => 'nfe', 'valor_centavos' => 100, 'status' => 'pendente', 'provedor' => 'x',
            'solicitado_por' => $this->usuario(Papel::Gerente, $this->u)->id, 'chave_idempotencia' => str_repeat('b', 64)]);
    }

    public function test_cliente_sem_veiculo_e_cadastrado_de_dentro_da_nota(): void
    {
        config(['oficina.integracoes' => 'simulado']);
        $rec = $this->usuario(Papel::Recepcao, $this->u);
        $this->entrarComo($rec)->get(route('notas-avulsas.create', ['q' => 'Fulano']))->assertOk()->assertSee('Cliente novo? Cadastrar aqui')->assertSee('para_nota', false);

        $this->entrarComo($rec)->post(route('clientes.store'), ['para_nota' => 1, 'nome' => 'Carlos Mendes', 'documento' => '529.982.247-25', 'telefone' => '(98) 99123-4567',
            'cep' => '65057-787', 'logradouro' => 'Rua Sete', 'numero' => '15', 'bairro' => 'São Raimundo', 'cidade' => 'São Luís', 'uf' => 'MA'])->assertSessionHasNoErrors()
            ->assertRedirect(route('notas-avulsas.create', ['cliente' => Cliente::where('nome', 'Carlos Mendes')->value('id')]));
        $c = Cliente::where('nome', 'Carlos Mendes')->sole();
        $this->assertSame(['52998224725', 'MA', 0], [$c->documento, $c->uf, $c->veiculos()->count()]);
        $this->entrarComo($rec)->get(route('notas-avulsas.create', ['cliente' => $c->id]))->assertOk()->assertSee('Carlos Mendes')->assertSee('Emitir NF-e');

        // Sem para_nota, o cadastro comum continua indo para a ficha do cliente.
        $this->entrarComo($rec)->post(route('clientes.store'), ['nome' => 'Outro', 'telefone' => '61988887777'])->assertRedirect(route('clientes.show', Cliente::where('nome', 'Outro')->value('id')));
    }

    /** Rejeição 232 em produção (19/11/2026, NA-00001): cliente empresa contribuinte em AL exige a IE dele na nota. */
    public function test_cliente_contribuinte_leva_ie_sem_difal_e_venda_vira_6102(): void
    {
        Empresa::atual()->forceFill(['fiscal' => ['inscricao_estadual' => '0854766700119']])->save();
        $al = ['logradouro' => 'Avenida Doutor André Papini Góis', 'numero' => '26', 'complemento' => '', 'bairro' => 'Cidade Universitária', 'cidade' => 'Maceió', 'uf' => 'AL', 'cep' => '57073130'];
        $remessa = $this->dadosXml(new NotaAvulsa(['operacao' => 'remessa', 'canal' => 'internet', 'frete' => 9, 'pagamento' => '90']), DadosFiscais::atuais(), $al, 20000);
        $remessa['cliente_documento'] = '27156664000109';
        $xml = $this->assinar($remessa + ['cliente_ie' => '247272965']); // XSD oficial
        foreach (['<indIEDest>1</indIEDest>', '<IE>247272965</IE>', '<indFinal>0</indFinal>', '<CFOP>6949</CFOP>', '<CNPJ>27156664000109</CNPJ>', '<tPag>90</tPag>'] as $t) {
            $this->assertStringContainsString($t, $xml, $t);
        }
        $this->assertStringNotContainsString('ICMSUFDest', $xml);

        $venda = $this->dadosXml(new NotaAvulsa(['operacao' => 'venda', 'canal' => 'internet', 'frete' => 9, 'pagamento' => '17']), DadosFiscais::atuais(), $al) + ['cliente_ie' => '247272965'];
        $this->assertStringContainsString('<CFOP>6102</CFOP>', (new NfeSefaz(1))->xml($venda));
        // Sem IE continua não contribuinte (como antes).
        $this->assertStringContainsString('<indIEDest>9</indIEDest>', (new NfeSefaz(1))->xml(array_diff_key($venda, ['cliente_ie' => 1])));

        // Cadastro guarda a IE (só números).
        config(['oficina.integracoes' => 'simulado']);
        $this->entrarComo($this->usuario(Papel::Recepcao, $this->u))->post(route('clientes.store'), ['para_nota' => 1, 'nome' => 'T R C TENORIO LTDA', 'documento' => '27.156.664/0001-09',
            'inscricao_estadual' => '24.727.296-5', 'telefone' => '82999998888'])->assertSessionHasNoErrors();
        $this->assertSame('247272965', Cliente::firstWhere('documento', '27156664000109')->inscricao_estadual);
    }

    /** 19/11/2026: cliente sem endereço/CPF é completado na própria tela da nota (avulsa ou OS), sem ir à ficha. */
    public function test_completa_dados_do_cliente_sem_sair_da_nota(): void
    {
        config(['oficina.fiscal.modo' => 'homologacao']); // emissor real: a tela confere o que falta antes de emitir
        $rec = $this->usuario(Papel::Recepcao, $this->u);
        $cli = Cliente::create(['nome' => 'Sem Endereço', 'telefone' => '61991112222']);
        $nova = route('notas-avulsas.create', ['cliente' => $cli->id]);

        $this->entrarComo($rec)->get($nova)->assertOk()->assertSee('Completar dados do cliente para a nota')->assertDontSee('Emitir NF-e');
        $this->entrarComo($this->usuario(Papel::Mecanico, $this->u))->put(route('clientes.dados-nota', $cli), ['documento' => '52998224725'])->assertForbidden();
        $this->entrarComo($rec)->from($nova)->put(route('clientes.dados-nota', $cli), ['documento' => '52998224725', 'logradouro' => 'Rua A'])
            ->assertSessionHasErrors(['bairro', 'cidade', 'uf', 'cep']);
        $this->entrarComo($rec)->from($nova)->put(route('clientes.dados-nota', $cli), ['documento' => '529.982.247-25', 'inscricao_estadual' => '',
            'cep' => '57073-130', 'logradouro' => 'Avenida Doutor André Papini Góis', 'numero' => '26', 'bairro' => 'Cidade Universitária', 'cidade' => 'Maceió', 'uf' => 'AL'])
            ->assertSessionHasNoErrors()->assertRedirect($nova.'#fiscal');
        $this->assertSame(['52998224725', 'AL', 'Cidade Universitária'], [$cli->fresh()->documento, $cli->fresh()->uf, $cli->fresh()->bairro]);
        $this->entrarComo($rec)->get($nova)->assertSee('Emitir NF-e')->assertDontSee('Completar dados do cliente para a nota');

        // Na OS: cliente sem CPF vê o formulário na aba de documentos.
        $o = app(\App\Services\Entradas::class)->registrar($rec, ['unidade_id' => $this->u->id, 'nome' => 'Sem CPF', 'telefone' => '61993334444', 'placa' => 'ABC1D23', 'motivo' => 'x']);
        $ger = $this->usuario(Papel::Gerente, $this->u);
        app(\App\Services\Ordens::class)->iniciarDiagnostico($o, $this->usuario(Papel::Mecanico, $this->u));
        app(\App\Services\Orcamentos::class)->aprovarDireto(app(\App\Services\Orcamentos::class)->salvarRascunho($o, $ger, [['descricao' => 'Alinhamento', 'quantidade' => '1', 'preco' => '100']]), $ger);
        $this->entrarComo($rec)->get(route('ordens.show', [$o->fresh(), 'aba' => 'documentos']))->assertOk()
            ->assertSee('Completar dados do cliente para a nota')->assertSee('cadastre o CPF ou CNPJ');
    }

    /** Rejeição 232 em produção (26/11/2026, PRONTO RODAS): a ficha tinha CNPJ e endereço, mas não a IE; a tela da nota pede a IE junto da rejeição. */
    public function test_rejeicao_232_pede_ie_na_tela_da_nota(): void
    {
        config(['oficina.integracoes' => 'simulado']);
        $rec = $this->usuario(Papel::Recepcao, $this->u);
        $cli = Cliente::create(['nome' => 'Pronto Rodas', 'telefone' => '21991234567', 'documento' => '34308439000106', 'logradouro' => 'Rua Capitão Sampaio', 'numero' => '117',
            'bairro' => 'Del Castilho', 'cidade' => 'Rio de Janeiro', 'uf' => 'RJ', 'cep' => '20760011']);
        $this->entrarComo($rec)->post(route('notas-avulsas.store'), ['cliente_id' => $cli->id, 'unidade_id' => $this->u->id, 'operacao' => 'venda', 'canal' => 'internet',
            'frete' => 9, 'pagamento' => '17', 'itens' => [['descricao' => 'Bordas', 'quantidade' => '1', 'preco' => '200', 'ncm' => '87087090']]])->assertSessionHasNoErrors();
        $n = NotaAvulsa::sole();
        $doc = FiscalDocument::sole();
        $doc->forceFill(['status' => 'rejeitado', 'mensagem' => '232 Rejeicao: IE do destinatario nao informada'])->save();
        $tela = route('notas-avulsas.show', $n);

        $this->entrarComo($rec)->get($tela)->assertOk()->assertSee('contribuinte de ICMS')->assertSee('Tentar de novo');
        $this->entrarComo($rec)->from($tela)->put(route('clientes.dados-nota', $cli), ['documento' => '34308439000106', 'inscricao_estadual' => '87.654.321',
            'cep' => '20760011', 'logradouro' => 'Rua Capitão Sampaio', 'numero' => '117', 'bairro' => 'Del Castilho', 'cidade' => 'Rio de Janeiro', 'uf' => 'RJ'])
            ->assertSessionHasNoErrors()->assertRedirect($tela.'#fiscal');
        $this->assertSame('87654321', preg_replace('/\D/', '', (string) $cli->fresh()->inscricao_estadual));
        $this->entrarComo($rec)->get($tela)->assertDontSee('contribuinte de ICMS')->assertSee('Tentar de novo');

        // Depois de autorizada, a rejeitada antiga não oferece "Tentar de novo" (o servidor já recusava).
        $this->entrarComo($rec)->post(route('notas-avulsas.reprocessar', [$n, $doc]))->assertSessionHasNoErrors();
        $this->assertSame(1, FiscalDocument::where('status', 'autorizado')->count());
        $this->entrarComo($rec)->get($tela)->assertDontSee('Tentar de novo');
    }
}
