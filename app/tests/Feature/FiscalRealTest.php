<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Integracoes\Fiscal\NfeSefaz;
use App\Integracoes\Fiscal\NfseNacional;
use App\Jobs\ProcessarDocumentoFiscal;
use App\Models\AuditEvent;
use App\Models\CertificadoDigital;
use App\Models\Empresa;
use App\Models\FiscalDocument;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Services\Entradas;
use App\Services\Fiscal;
use App\Services\Orcamentos;
use App\Services\Ordens;
use App\Support\DadosFiscais;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Signer;
use NFePHP\NFe\Tools;
use Tests\TestCase;

/**
 * Etapa 2 fiscal (09/11/2026): certificado A1 e emissão real (NFS-e Sefin Nacional, NF-e SEFAZ) com a Fazenda simulada
 * por Http::fake. Certificado de teste fictício (tests/fixtures, CNPJ de teste 11.222.333/0001-81, senha "senha-teste").
 * A comunicação real com a Fazenda é verificada à parte, em homologação, com o certificado da empresa.
 */
class FiscalRealTest extends TestCase
{
    private const CNPJ = '11222333000181';

    private Unidade $unidade;

    private array $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        Empresa::create(['razao_social' => 'Oficina Teste Ltda', 'nome_fantasia' => 'Oficina Teste', 'cnpj' => self::CNPJ, 'telefone' => '6133334444',
            'email' => 'contato@oficina.test', 'cep' => '70040010', 'logradouro' => 'Rua das Rodas', 'numero' => '17', 'bairro' => 'Asa Sul', 'cidade' => 'Brasília', 'uf' => 'DF']);
        $this->unidade = Unidade::factory()->create();
    }

    private function pfx(string $nome = 'certificado-teste.pfx'): UploadedFile
    {
        return new UploadedFile(base_path('tests/fixtures/'.$nome), $nome, 'application/x-pkcs12', null, true);
    }

    private function guardarCertificado(): void
    {
        $this->entrarComo($this->usuario(Papel::Proprietario))->post(route('certificado.store'), ['certificado' => $this->pfx(), 'senha' => 'senha-teste'])
            ->assertSessionHasNoErrors();
    }

    private function aprovada(array $itens = [['descricao' => 'Alinhamento', 'quantidade' => '1', 'preco' => '350']]): Ordem
    {
        $rec = $this->usuario(Papel::Recepcao, $this->unidade);
        $ger = $this->usuario(Papel::Gerente, $this->unidade);
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana Costa', 'telefone' => '61991234567', 'placa' => 'ABC1D23', 'motivo' => 'x']);
        DB::table('clientes')->where('id', $o->cliente_id)->update(['documento' => '52998224725']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->usuario(Papel::Mecanico, $this->unidade));
        $v = app(Orcamentos::class)->salvarRascunho($o, $ger, $itens);
        app(Orcamentos::class)->aprovarDireto($v, $ger);

        return $o->fresh();
    }

    private function sefinAutoriza(): void
    {
        Http::fake(function (Request $r) {
            $this->enviados[] = $r;
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/nfse')) {
                $dps = gzdecode(base64_decode($r['dpsXmlGZipB64']));
                preg_match('/<nDPS>(\d+)</', $dps, $n);

                return Http::response(['chaveAcesso' => '53001082'.self::CNPJ.str_pad($n[1], 28, '0', STR_PAD_LEFT),
                    'nfseXmlGZipB64' => base64_encode(gzencode('<NFSe><infNFSe><nNFSe>'.(100 + (int) $n[1]).'</nNFSe></infNFSe>'.$dps.'</NFSe>'))], 201);
            }
            if (str_contains($r->url(), '/danfse/')) {
                return Http::response('%PDF-1.4 teste', 200, ['Content-Type' => 'application/pdf']);
            }
            if (str_contains($r->url(), '/eventos')) {
                return Http::response(['eventoXmlGZipB64' => base64_encode(gzencode('<evento/>'))], 201);
            }
            if (str_contains($r->url(), '/dps/')) {
                return Http::response('', 404);
            }

            return Http::response(['erro' => 'inesperado'], 500);
        });
    }

    // ---------- Certificado ----------

    public function test_certificado_e_guardado_criptografado_e_so_quem_configura_envia(): void
    {
        $ger = $this->usuario(Papel::Gerente, $this->unidade);
        $this->entrarComo($ger)->get(route('certificado.edit'))->assertForbidden();
        $this->entrarComo($ger)->post(route('certificado.store'), ['certificado' => $this->pfx(), 'senha' => 'senha-teste'])->assertForbidden();

        $dono = $this->usuario(Papel::Proprietario);
        $this->entrarComo($dono)->post(route('certificado.store'), ['certificado' => $this->pfx(), 'senha' => 'errada'])->assertSessionHasErrors('certificado');
        $this->assertSame(0, CertificadoDigital::count());

        $this->entrarComo($dono)->post(route('certificado.store'), ['certificado' => $this->pfx(), 'senha' => 'senha-teste'])->assertSessionHasNoErrors();
        $c = CertificadoDigital::sole();
        $this->assertSame([self::CNPJ, 'OFICINA TESTE LTDA'], [$c->documento, $c->titular]);
        $bruto = DB::table('certificados_digitais')->first();
        $this->assertStringNotContainsString('senha-teste', $bruto->senha, 'senha cifrada no banco');
        $this->assertStringNotContainsString(substr(base64_encode(file_get_contents(base_path('tests/fixtures/certificado-teste.pfx'))), 0, 40), $bruto->pfx);
        $this->assertSame(self::CNPJ, $c->certificado()->getCnpj(), 'abre de novo a partir do banco');

        $this->entrarComo($dono)->get(route('certificado.edit'))->assertOk()->assertSee('11.222.333/0001-81')->assertDontSee('senha-teste');
        $this->assertTrue(AuditEvent::where('acao', 'certificado_enviado')->exists());

        // Formato antigo (RC2) também abre: provedor legacy do OpenSSL ligado na imagem.
        $this->entrarComo($dono)->post(route('certificado.store'), ['certificado' => $this->pfx('certificado-teste-legado.pfx'), 'senha' => 'senha-teste'])->assertSessionHasNoErrors();
        $this->assertSame(1, CertificadoDigital::count(), 'só o atual fica guardado');
    }

    public function test_senha_do_certificado_nao_fica_na_sessao_apos_erro(): void
    {
        $this->entrarComo($this->usuario(Papel::Proprietario))->from(route('certificado.edit'))
            ->post(route('certificado.store'), ['certificado' => $this->pfx(), 'senha' => 'senha-que-vazaria'])->assertSessionHasErrors('certificado');
        $this->assertStringNotContainsString('senha-que-vazaria', serialize(session()->all()));
    }

    public function test_certificado_de_outro_cnpj_e_recusado(): void
    {
        Empresa::atual()->update(['cnpj' => '11444777000161']);
        $this->entrarComo($this->usuario(Papel::Proprietario))->post(route('certificado.store'), ['certificado' => $this->pfx(), 'senha' => 'senha-teste'])
            ->assertSessionHasErrors(['certificado' => 'Este certificado é do CNPJ 11.222.333/0001-81, mas a empresa cadastrada está com o CNPJ 11.444.777/0001-61. Corrija em Identidade da empresa ou envie o certificado certo.']);
        $this->assertSame(0, CertificadoDigital::count());
    }

    public function test_banco_demo_nunca_liga_a_emissao_real(): void
    {
        $ler = function (string $banco) {
            $_ENV['OFICINA_FISCAL'] = $_SERVER['OFICINA_FISCAL'] = 'producao';
            $_ENV['OFICINA_BANCO'] = $_SERVER['OFICINA_BANCO'] = $banco;
            try {
                return (require config_path('oficina.php'))['fiscal']['modo'];
            } finally {
                unset($_ENV['OFICINA_FISCAL'], $_SERVER['OFICINA_FISCAL'], $_ENV['OFICINA_BANCO'], $_SERVER['OFICINA_BANCO']);
            }
        };
        $this->assertSame('producao', $ler('principal'));
        $this->assertNull($ler('demo'));
    }

    // ---------- NFS-e Nacional ----------

    public function test_nfse_homologacao_assina_envia_e_guarda_pdf_xml_e_chave(): void
    {
        config(['oficina.fiscal.modo' => 'homologacao']);
        $this->guardarCertificado();
        $this->sefinAutoriza();
        $o = $this->aprovada();
        app(Fiscal::class)->solicitar($o, $this->usuario(Papel::Recepcao, $this->unidade)); // fila síncrona nos testes

        $d = FiscalDocument::where('ordem_id', $o->id)->sole();
        $this->assertSame(['autorizado', 2, '3', 1, '101', 'homologacao'], [$d->status, $d->ambiente, $d->serie, (int) $d->numero_dps, $d->numero, $d->provedor]);
        $this->assertSame(50, strlen($d->chave_acesso));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($d->arquivo));
        $this->assertStringContainsString('<nNFSe>101</nNFSe>', Storage::disk('local')->get($d->xml));

        $post = collect($this->enviados)->first(fn ($r) => $r->method() === 'POST');
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse', $post->url());
        $dps = gzdecode(base64_decode($post['dpsXmlGZipB64']));
        $this->assertTrue(Signer::isSigned($dps, 'infDPS'), 'assinatura XMLDSig válida');
        $this->assertStringContainsString('rsa-sha256', $dps);
        $id = NfseNacional::idDps('5300108', self::CNPJ, '3', 1);
        $this->assertSame(45, strlen($id));
        $this->assertStringContainsString('Id="'.$id.'"', $dps);
        foreach (['<tpAmb>2</tpAmb>', '<serie>3</serie>', '<nDPS>1</nDPS>', '<CPF>52998224725</CPF>', '<xNome>Mariana Costa</xNome>', '<opSimpNac>3</opSimpNac>',
            '<cTribNac>140101</cTribNac>', '<vServ>350.00</vServ>', '<vTotTribMun>7.00</vTotTribMun>', '<xDescServ>OS '.$o->numero.' - 1 x Alinhamento</xDescServ>'] as $t) {
            $this->assertStringContainsString($t, $dps);
        }

        // A segunda ordem pega o número seguinte.
        $o2 = $this->aprovada();
        app(Fiscal::class)->solicitar($o2, $this->usuario(Papel::Recepcao, $this->unidade));
        $this->assertSame(2, (int) FiscalDocument::where('ordem_id', $o2->id)->value('numero_dps'));

        // Tela da OS: chave, aviso de homologação e XML; download do PDF e do XML.
        $rec = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->entrarComo($rec)->get("/ordens/{$o->numero}?aba=documentos")->assertSee($d->chave_acesso)->assertSee('Homologação: sem validade fiscal.')->assertSee('Baixar XML');
        $this->entrarComo($rec)->get(route('fiscal.baixar', [$o, $d]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->entrarComo($rec)->get(route('fiscal.baixar', [$o, $d, 'xml' => 1]))->assertOk()->assertHeader('Content-Type', 'application/xml');
    }

    public function test_nfse_rejeitada_mostra_motivo_e_nova_tentativa_reaproveita_o_numero(): void
    {
        config(['oficina.fiscal.modo' => 'homologacao']);
        $this->guardarCertificado();
        Http::fake(['*/dps/*' => Http::response('', 404), '*/nfse' => Http::response(['erros' => [['Codigo' => 'E0312', 'Descricao' => 'Código de tributação inválido']]], 400)]);
        $o = $this->aprovada();
        $rec = $this->usuario(Papel::Recepcao, $this->unidade);
        app(Fiscal::class)->solicitar($o, $rec);
        $r = FiscalDocument::where('ordem_id', $o->id)->sole();
        $this->assertSame(['rejeitado', 'E0312 Código de tributação inválido', 1], [$r->status, $r->mensagem, (int) $r->numero_dps]);

        Http::swap(new Factory); // troca a Fazenda simulada
        Http::preventStrayRequests();
        $this->sefinAutoriza();
        $novo = app(Fiscal::class)->reprocessar($r, $rec)->fresh();
        $this->assertSame(['autorizado', 1], [$novo->status, (int) $novo->numero_dps], 'rejeitada não consome número');
        $this->assertNull($r->fresh()->numero_dps);
    }

    public function test_nfse_fora_do_ar_fica_pendente_e_repete_o_mesmo_numero(): void
    {
        config(['oficina.fiscal.modo' => 'homologacao']);
        $this->guardarCertificado();
        Http::fake(['*/dps/*' => Http::response('', 404), '*/nfse' => Http::response('Service Unavailable', 503)]);
        $o = $this->aprovada();
        try {
            app(Fiscal::class)->solicitar($o, $this->usuario(Papel::Recepcao, $this->unidade));
        } catch (\RuntimeException) {
        }
        $d = FiscalDocument::where('ordem_id', $o->id)->sole();
        $this->assertSame(['pendente', 1], [$d->status, (int) $d->numero_dps]);
        $this->assertStringContainsString('indisponível', $d->mensagem);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->sefinAutoriza();
        (new ProcessarDocumentoFiscal($d->id))->handle(app(Fiscal::class));
        $this->assertSame(['autorizado', 1], [$d->fresh()->status, (int) $d->fresh()->numero_dps]);
        $this->assertTrue(collect($this->enviados)->contains(fn ($r) => str_contains($r->url(), '/dps/'.NfseNacional::idDps('5300108', self::CNPJ, '3', 1))), 'consulta a DPS antes de reenviar');
    }

    public function test_producao_respeita_proximo_numero_aumentado_e_denegada_nao_devolve_numero(): void
    {
        $doc = fn (Ordem $o, string $status, ?int $n, ?string $msg) => FiscalDocument::create(['ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'orcamento_id' => $o->orcamentoAprovado->id,
            'tipo' => 'nfe', 'valor_centavos' => 100, 'status' => $status, 'provedor' => 'producao', 'solicitado_por' => $this->usuario(Papel::Recepcao, $this->unidade)->id,
            'chave_idempotencia' => bin2hex(random_bytes(16)), 'ambiente' => 1, 'serie' => '1', 'numero_dps' => $n, 'mensagem' => $msg]);
        $numerar = fn (FiscalDocument $d, array $f) => (int) (new \ReflectionMethod(Fiscal::class, 'numerar'))->invoke(app(Fiscal::class), $d, 1, $f)->numero_dps;
        $f = ['nfe_serie' => '1', 'nfse_serie' => '3', 'nfe_proximo_numero' => '20', 'nfse_proximo_numero' => ''];
        $o = $this->aprovada();
        $this->assertSame(20, $numerar($doc($o, 'pendente', null, null), $f));
        $this->assertSame(21, $numerar($doc($this->aprovada(), 'pendente', null, null), $f));
        $this->assertSame(50, $numerar($doc($this->aprovada(), 'pendente', null, null), ['nfe_proximo_numero' => '50'] + $f), 'aumentar o próximo número vale');

        $o2 = $this->aprovada();
        $doc($o2, 'rejeitado', 49, '301 Uso denegado');
        $this->assertSame(51, $numerar($doc($this->aprovada(), 'pendente', null, null), ['nfe_proximo_numero' => '1'] + $f));
        $this->assertSame(52, $numerar($doc($o2, 'pendente', null, null), ['nfe_proximo_numero' => '1'] + $f), 'denegada mantém o número; a nova tentativa pega outro');
    }

    public function test_certificado_corrompido_nao_expoe_a_senha(): void
    {
        $this->guardarCertificado();
        $c = CertificadoDigital::sole();
        $c->pfx = base64_encode('lixo');
        try {
            $c->certificado();
            $this->fail('deveria falhar');
        } catch (\RuntimeException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('senha-teste', $e->getTraceAsString().$e->getMessage());
        }
    }

    public function test_nfse_cancelamento_envia_evento_assinado(): void
    {
        config(['oficina.fiscal.modo' => 'homologacao']);
        $this->guardarCertificado();
        $this->sefinAutoriza();
        $o = $this->aprovada();
        app(Fiscal::class)->solicitar($o, $this->usuario(Papel::Recepcao, $this->unidade));
        $d = FiscalDocument::where('ordem_id', $o->id)->sole();

        app(Fiscal::class)->cancelar($d, $this->usuario(Papel::Gerente, $this->unidade), 'Valor lançado errado na ordem de serviço');
        $this->assertSame('cancelado', $d->fresh()->status);
        $ev = collect($this->enviados)->first(fn ($r) => str_contains($r->url(), '/eventos'));
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse/'.$d->chave_acesso.'/eventos', $ev->url());
        $xml = gzdecode(base64_decode($ev['pedidoRegistroEventoXmlGZipB64']));
        $this->assertTrue(Signer::isSigned($xml, 'infPedReg'));
        $this->assertStringContainsString('Id="PRE'.$d->chave_acesso.'101101"', $xml);
        $this->assertStringContainsString('<xMotivo>Valor lançado errado na ordem de serviço</xMotivo>', $xml);
    }

    public function test_producao_exige_proximo_numero_e_certificado(): void
    {
        config(['oficina.fiscal.modo' => 'producao']);
        Http::fake();
        $o = $this->aprovada();
        try {
            app(Fiscal::class)->solicitar($o, $this->usuario(Papel::Recepcao, $this->unidade));
        } catch (\RuntimeException) {
        }
        $d = FiscalDocument::where('ordem_id', $o->id)->sole();
        $this->assertSame('pendente', $d->status);
        $this->assertStringContainsString('próximo número da NFS-e', $d->mensagem);
        $this->assertNull($d->numero_dps);

        Empresa::atual()->forceFill(['fiscal' => ['nfse_proximo_numero' => '19']])->save();
        try {
            (new ProcessarDocumentoFiscal($d->id))->handle(app(Fiscal::class));
        } catch (\RuntimeException) {
        }
        $this->assertStringContainsString('Certificado digital não enviado', $d->fresh()->mensagem);
        $this->assertSame(19, (int) $d->fresh()->numero_dps, 'produção continua a numeração do sistema antigo');
        Http::assertNothingSent();
    }

    // ---------- NF-e ----------

    public function test_nfe_monta_xml_valido_no_schema_e_chave_com_digito_correto(): void
    {
        config(['oficina.fiscal.modo' => 'homologacao']);
        $this->guardarCertificado();
        Empresa::atual()->forceFill(['fiscal' => ['inscricao_estadual' => '0734567800155']])->save();
        $dados = ['tipo' => 'nfe', 'chave' => str_repeat('a', 64), 'fiscal' => DadosFiscais::atuais(), 'serie' => '1', 'numero_dps' => 8,
            'criado_em' => now(), 'valor_centavos' => 39000, 'ordem' => 'A-1', 'cliente' => 'Mariana Costa', 'cliente_documento' => '52998224725', 'cliente_email' => null,
            'itens' => [['descricao' => 'Roda aro 17', 'quantidade' => '2', 'total_centavos' => 40000, 'ncm' => '87087090', 'codigo' => 5]]];
        $chave = NfeSefaz::chave($dados, self::CNPJ);
        $this->assertSame(44, strlen($chave));

        $xml = (new NfeSefaz(2))->xml($dados); // Make recalcula a chave e acusa divergência em getErrors()
        $tools = new Tools(json_encode(['atualizacao' => '2026-11-09 00:00:00', 'tpAmb' => 2, 'razaosocial' => 'Oficina Teste Ltda', 'siglaUF' => 'DF',
            'cnpj' => self::CNPJ, 'schemes' => 'PL_010_V1.30', 'versao' => '4.00']), CertificadoDigital::atual()->certificado());
        $assinado = $tools->signNFe($xml); // valida no XSD antes de assinar
        $this->assertStringContainsString('Id="NFe'.$chave.'"', $assinado);
        foreach (['<vDesc>10.00</vDesc>', '<vNF>390.00</vNF>', '<CSOSN>102</CSOSN>', '<CFOP>5102</CFOP>', '<tpAmb>2</tpAmb>', 'SEM VALOR FISCAL'] as $t) {
            $this->assertStringContainsString($t, $assinado);
        }
    }

    public function test_nfe_sem_inscricao_estadual_nao_envia(): void
    {
        config(['oficina.fiscal.modo' => 'homologacao']);
        $this->guardarCertificado();
        Http::fake();
        $o = $this->aprovada([['descricao' => 'Válvula', 'quantidade' => '1', 'preco' => '20', 'tipo' => 'produto']]);
        try {
            app(Fiscal::class)->solicitar($o, $this->usuario(Papel::Recepcao, $this->unidade));
        } catch (\RuntimeException) {
        }
        $d = FiscalDocument::where('ordem_id', $o->id)->sole();
        $this->assertSame(['nfe', 'pendente'], [$d->tipo, $d->status]);
        $this->assertStringContainsString('inscrição estadual', $d->mensagem);
        Http::assertNothingSent();
    }
}
