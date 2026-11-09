<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Cliente;
use App\Models\Veiculo;
use Tests\TestCase;

/** Importação do sistema antigo (09/11/2026): nada se perde, repetível, simulação não grava. */
class ImportarSgaTest extends TestCase
{
    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasta = sys_get_temp_dir().'/sga-teste-'.uniqid();
        mkdir($this->pasta);
        $csv = function (string $t, array $linhas) {
            $f = fopen("{$this->pasta}/{$t}.csv", 'w');
            foreach ($linhas as $l) {
                fputcsv($f, $l, escape: '');
            }
            fclose($f);
        };
        $cab = ['CODIGO', 'DATACAD', 'NOME', 'FONE1', 'FONE2', 'FONE3', 'ENDERECO', 'NUMERO', 'BAIRRO', 'CEP', 'CHMUNICIPIO', 'CPF', 'CGC', 'EMAIL', 'OBS', 'NOME_FANTASIA', 'RG', 'TIPOPESSOA'];
        $csv('TBL_CLIENTE', [$cab,
            ['10', '2015-03-02', 'JOSÉ DA SILVA', '(61) 99876-5432', '', '', 'QD 5 CASA 3', '3', 'GUARÁ', '71000-000', '1', '123.456.789-09', '', 'jose@x.com', 'Cliente antigo', '', '1234567 SSP', 'F'],
            ['11', '2020-01-01', 'OFICINA PARCEIRA LTDA', '', '', '', '', '', '', '', '', '', '12.345.678/0001-95', '', '', 'PARCEIRA', '', 'J'],
            ['12', '2021-01-01', 'MARIA SEM CARRO', '6133334444', '', '', '', '', '', '', '', '', '', '', '', '', '', 'F']]);
        $csv('TBL_VEICULO', [['CODIGO', 'PLACA', 'CHCLIENTE', 'FABRICANTE_OLD', 'MODELO_OLD', 'ANOMOD', 'COR_OLD', 'CHASSI', 'RENAVAM', 'CHFABRICANTE', 'CHMODELO', 'CHCOR', 'DATA_CAD'],
            ['1', 'JIK-2116', '10', '', '', '13/14', '', '9BW0000', '0123', '1', '1', '1', '2015-03-02'],
            ['2', '1111111', '11', 'X', 'Y', '', 'AZUL', '', '', '', '', '', ''],
            ['3', 'EXI-1A23', '11', '', '', '', '', '', '', '', '', '', '']]);
        $csv('TBL_MUNICIPIO', [['CODIGO', 'UF', 'MUNICIPIO'], ['1', 'DF', 'BRASÍLIA']]);
        $csv('TBL_COR', [['CODIGO', 'DESCRICAO'], ['1', 'PRETA']]);
        $csv('TBL_FABRICANTE_VEICULO', [['CODIGO', 'DESCRICAO'], ['1', 'HYUNDAI']]);
        $csv('TBL_MODELO_VEICULO', [['CODIGO', 'DESCRICAO'], ['1', 'ELANTRA']]);
        // Arquivo completo: OS ligada a cliente/carro, serviço com nome do catálogo, tabela com senha, log sem vínculo.
        $csv('TBL_OS', [['CODIGO', 'CHCLIENTE', 'CHVEICULO', 'DATAIN', 'DATAOUT', 'KM', 'TOTAL', 'STATUS', 'CANCELADA', 'PAG', 'OBS'],
            ['500', '10', '1', '2024-05-10', '2024-05-11', '85000', '350.00', 'F', 'N', 'S', 'Barulho na suspensão']]);
        $csv('TBL_SERVICO', [['CODIGO', 'CHOS', 'CHSERVICOP', 'QTD', 'PRECO', 'DESCRICAO'], ['900', '500', '7', '2.00', '175.00', '']]);
        $csv('TBL_SERVICOP', [['CODIGO', 'NOME'], ['7', 'TROCA DE BIELETA']]);
        $csv('TBL_CONFIG_EMAIL', [['CODIGO', 'CONTA', 'SENHA'], ['1', 'oficina@x.com', 'segredo123']]);
        $csv('TBL_LOG', [['CODIGO', 'TEXTO'], ['1', 'login'], ['2', "texto RTF\0com nulo"]]);
    }

    public function test_arquivo_completo_ligado_ao_cliente_sem_poluir_a_operacao(): void
    {
        $this->artisan('oficina:importar-sga-arquivo', ['pasta' => $this->pasta])->assertFailed(); // clientes primeiro
        $this->artisan('oficina:importar-sga', ['pasta' => $this->pasta, '--executar' => true])->assertSuccessful();
        $this->artisan('oficina:importar-sga-arquivo', ['pasta' => $this->pasta])->assertSuccessful()->expectsOutputToContain('SIMULAÇÃO');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('legado_registros')->count(), 'simulação não grava');

        $this->artisan('oficina:importar-sga-arquivo', ['pasta' => $this->pasta, '--executar' => true])->assertSuccessful();
        $reg = \Illuminate\Support\Facades\DB::table('legado_registros');
        $jose = Cliente::where('legado_codigo', 10)->sole();
        $os = (clone $reg)->where('tabela', 'TBL_OS')->sole();
        $this->assertSame([$jose->id, Veiculo::where('legado_codigo', 1)->value('id'), 'OS:500'], [$os->cliente_id, $os->veiculo_id, $os->grupo]);
        $serv = (clone $reg)->where('tabela', 'TBL_SERVICO')->sole();
        $this->assertSame([$jose->id, 'OS:500', 'TROCA DE BIELETA'], [$serv->cliente_id, $serv->grupo, json_decode($serv->dados, true)['_servico']]);
        $this->assertSame('[removido]', json_decode((clone $reg)->where('tabela', 'TBL_CONFIG_EMAIL')->value('dados'), true)['SENHA']);
        $this->assertSame(2, (clone $reg)->where('tabela', 'TBL_LOG')->whereNull('cliente_id')->count(), 'tabelas sem vínculo também entram');
        $this->assertSame('texto RTF␀com nulo', json_decode((clone $reg)->where('tabela', 'TBL_LOG')->where('linha', 2)->value('dados'), true)['TEXTO']);
        $total = (clone $reg)->count();

        $this->artisan('oficina:importar-sga-arquivo', ['pasta' => $this->pasta, '--executar' => true])->assertSuccessful();
        $this->assertSame($total, \Illuminate\Support\Facades\DB::table('legado_registros')->count(), 'repetir não duplica');

        // Não polui: nenhuma OS/pagamento novo; a ficha mostra o histórico só para consulta.
        $this->assertSame([0, 0], [\App\Models\Ordem::count(), \App\Models\Pagamento::count()]);
        $this->entrarComo($this->usuario(Papel::Proprietario))->get(route('clientes.show', $jose))->assertOk()
            ->assertSee('Histórico no sistema antigo')->assertSee('OS 500')->assertSee('TROCA DE BIELETA')->assertSee('R$ 350,00')
            ->assertSee('Barulho na suspensão')->assertSee('Finalizada')->assertSee('85000');
        $this->get('/')->assertOk();
    }

    public function test_simula_importa_sem_perder_nada_e_repete_sem_duplicar(): void
    {
        $outro = Cliente::create(['nome' => 'Dono atual', 'telefone' => '61900000000']);
        $existente = new Veiculo(['placa' => 'EXI1A23']);
        $existente->cliente_id = $outro->id;
        $existente->save();

        $this->artisan('oficina:importar-sga', ['pasta' => $this->pasta])->assertSuccessful()->expectsOutputToContain('SIMULAÇÃO');
        $this->assertSame(0, Cliente::whereNotNull('legado_codigo')->count(), 'simulação não grava');

        $this->artisan('oficina:importar-sga', ['pasta' => $this->pasta, '--executar' => true])->assertSuccessful()->expectsOutputToContain('IMPORTADO');
        $jose = Cliente::where('legado_codigo', 10)->sole();
        $this->assertSame(['JOSÉ DA SILVA', '61998765432', '12345678909', 'jose@x.com', '71000000', 'BRASÍLIA', 'DF', '2015-03-02'],
            [$jose->nome, $jose->telefone, $jose->documento, $jose->email, $jose->cep, $jose->cidade, $jose->uf, $jose->created_at->format('Y-m-d')]);
        $this->assertSame('1234567 SSP', $jose->legado['RG'], 'campo sem coluna própria fica no legado');
        $v = Veiculo::where('placa', 'JIK2116')->sole();
        $this->assertSame(['HYUNDAI', 'ELANTRA', 2014, 'PRETA', '9BW0000'], [$v->marca, $v->modelo, $v->ano, $v->cor, $v->legado['CHASSI']]);

        $pj = Cliente::where('legado_codigo', 11)->sole();
        $this->assertSame(['', '12345678000195'], [$pj->telefone, $pj->documento], 'sem telefone também entra');
        $this->assertTrue(Veiculo::where('placa', '1111111')->where('cliente_id', $pj->id)->exists(), 'placa fora do padrão entra como está');
        $this->assertSame($outro->id, $existente->fresh()->cliente_id, 'placa de outro cliente não é tomada');
        $this->assertSame('EXI-1A23', $pj->legado['_veiculos_nao_importados'][0]['PLACA'], '…mas fica guardada no legado');

        // Repetir não duplica nada.
        $this->artisan('oficina:importar-sga', ['pasta' => $this->pasta, '--executar' => true])->assertSuccessful();
        $this->assertSame(3, Cliente::whereNotNull('legado_codigo')->count());
        $this->assertSame(2, Veiculo::whereNotNull('legado_codigo')->count());
        $this->assertCount(1, $pj->fresh()->legado['_veiculos_nao_importados']);
        $this->assertDatabaseHas('audit_events', ['acao' => 'importacao_sga']);

        // Ficha: dados antigos visíveis; sem telefone não sugere todos os sem telefone como duplicados.
        $dono = $this->usuario(Papel::Proprietario);
        $this->entrarComo($dono)->get(route('clientes.show', $jose))->assertOk()->assertSee('Dados do sistema antigo')->assertSee('123.456.789-09')->assertSee('1234567 SSP');
        Cliente::create(['nome' => 'Outro sem fone', 'telefone' => '']);
        $this->get(route('clientes.show', $pj))->assertOk()->assertSee('12.345.678/0001-95')->assertDontSee('Possível cadastro duplicado');
    }
}
