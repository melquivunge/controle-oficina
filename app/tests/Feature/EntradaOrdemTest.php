<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Livewire\NovaEntrada;
use App\Models\AuditEvent;
use App\Models\Cliente;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\Veiculo;
use App\Services\Entradas;
use App\Services\Ordens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class EntradaOrdemTest extends TestCase
{
    private function entrada(Unidade $unidade, array $extra = []): array
    {
        return array_merge(['unidade_id' => $unidade->id, 'nome' => 'Mariana Costa', 'telefone' => '61991234567', 'placa' => 'ABC1D23', 'motivo' => 'Barulho ao frear'], $extra);
    }

    public function test_recepcao_registra_entrada_e_gera_ordem_numerada_com_auditoria_e_foto(): void
    {
        Storage::fake('local');
        $unidade = Unidade::factory()->create(['codigo' => 'ASN']);
        $recepcao = $this->usuario(Papel::Recepcao, $unidade);

        Livewire::actingAs($recepcao)->test(NovaEntrada::class)
            ->set('telefone', '(61) 99123-4567')->set('placa', 'abc-1d23')->set('nome', 'Mariana Costa')
            ->set('motivo', 'Barulho ao frear')->set('quilometragem', '45200')
            ->set('fotos', [$this->foto('frente.png')])
            ->call('salvar')->assertHasNoErrors()->assertRedirect(route('ordens.show', 'ASN-00001'));

        $ordem = Ordem::sole();
        $this->assertSame('ASN-00001', $ordem->numero);
        $this->assertSame(StatusOrdem::Entrada, $ordem->status);
        $this->assertSame($recepcao->id, $ordem->criado_por);
        $this->assertSame('61991234567', $ordem->cliente->telefone);
        $this->assertSame('ABC1D23', $ordem->veiculo->placa);
        $this->assertSame(45200, $ordem->quilometragem);
        $this->assertCount(1, $ordem->anexos);
        Storage::disk('local')->assertExists($ordem->anexos[0]->caminho);
        $this->assertDatabaseHas('audit_events', ['acao' => 'ordem_criada', 'entidade_id' => $ordem->id, 'unidade_id' => $unidade->id, 'ator_id' => $recepcao->id]);
    }

    public function test_campos_obrigatorios_e_formatos_sao_validados(): void
    {
        $recepcao = $this->usuario(Papel::Recepcao);
        Livewire::actingAs($recepcao)->test(NovaEntrada::class)
            ->set('telefone', '123')->set('placa', 'XX')->call('salvar')
            ->assertHasErrors(['telefone' => 'regex', 'placa' => 'regex', 'nome' => 'required', 'motivo' => 'required']);
        $this->assertSame(0, Ordem::count());
    }

    public function test_recepcao_nao_registra_em_outra_unidade_nem_pelo_formulario_nem_pelo_servico(): void
    {
        [$a, $b] = Unidade::factory()->count(2)->create();
        $recepcao = $this->usuario(Papel::Recepcao, $a);

        Livewire::actingAs($recepcao)->test(NovaEntrada::class)
            ->set('unidade_id', $b->id)->set('telefone', '61991234567')->set('placa', 'ABC1D23')->set('nome', 'X Y')->set('motivo', 'Teste')
            ->call('salvar')->assertHasErrors(['unidade_id']);

        $this->expectException(AuthorizationException::class);
        app(Entradas::class)->registrar($recepcao, $this->entrada($b));
    }

    public function test_mecanico_nao_acessa_nova_entrada(): void
    {
        $this->entrarComo($this->usuario(Papel::Mecanico))->get('/entradas/nova')->assertForbidden();
    }

    public function test_numeracao_e_por_unidade_e_reaproveita_cliente_e_veiculo(): void
    {
        $a = Unidade::factory()->create(['codigo' => 'ASN']);
        $b = Unidade::factory()->create(['codigo' => 'TAG']);
        $dono = \App\Models\User::factory()->proprietario()->create();
        $s = app(Entradas::class);

        $o1 = $s->registrar($dono, $this->entrada($a));
        $o2 = $s->registrar($dono, $this->entrada($a, ['nome' => 'MARIANA COSTA']));
        $o3 = $s->registrar($dono, $this->entrada($b));

        $this->assertSame(['ASN-00001', 'ASN-00002', 'TAG-00001'], [$o1->numero, $o2->numero, $o3->numero]);
        $this->assertSame(1, Cliente::count());
        $this->assertSame(1, Veiculo::count());
    }

    public function test_placa_existente_com_outro_cliente_transfere_veiculo_com_auditoria(): void
    {
        $unidade = Unidade::factory()->create();
        $dono = \App\Models\User::factory()->proprietario()->create();
        $s = app(Entradas::class);
        $s->registrar($dono, $this->entrada($unidade));
        try {
            $s->registrar($dono, $this->entrada($unidade, ['nome' => 'Novo Dono', 'telefone' => '61988887777']));
            $this->fail('transferência sem confirmação');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirmar_transferencia', $e->errors());
        }
        $o = $s->registrar($dono, $this->entrada($unidade, ['nome' => 'Novo Dono', 'telefone' => '61988887777', 'confirmar_transferencia' => true]));

        $this->assertSame('Novo Dono', $o->veiculo->fresh()->cliente->nome);
        $this->assertSame(2, Cliente::count());
        $this->assertDatabaseHas('audit_events', ['acao' => 'veiculo_transferido', 'entidade_id' => $o->veiculo_id]);
        $this->assertSame(2, Ordem::count(), 'atendimento anterior permanece');
    }

    public function test_contingencia_preserva_horario_original_e_autor_da_regularizacao(): void
    {
        $unidade = Unidade::factory()->create();
        $recepcao = $this->usuario(Papel::Recepcao, $unidade);
        $original = now('America/Sao_Paulo')->subHours(3)->format('Y-m-d\TH:i');

        Livewire::actingAs($recepcao)->test(NovaEntrada::class)
            ->set('telefone', '61991234567')->set('placa', 'ABC1234')->set('nome', 'Carlos Lima')->set('motivo', 'Troca de óleo')
            ->set('contingencia', true)->set('horario_original', now('America/Sao_Paulo')->addHour()->format('Y-m-d\TH:i'))->set('contingencia_nota', 'Folha 12 do caderno')
            ->call('salvar')->assertHasErrors('horario_original')
            ->set('horario_original', $original)->call('salvar')->assertHasNoErrors();

        $ordem = Ordem::sole();
        $this->assertTrue($ordem->contingencia);
        $this->assertSame($original, $ordem->entrada_em->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i'));
        $this->assertTrue($ordem->created_at->gt($ordem->entrada_em));
        $this->assertDatabaseHas('audit_events', ['acao' => 'ordem_criada_contingencia', 'ator_id' => $recepcao->id, 'motivo' => 'Folha 12 do caderno']);
    }

    public function test_isolamento_entre_unidades_na_ordem_lista_e_anexo(): void
    {
        Storage::fake('local');
        [$a, $b] = Unidade::factory()->count(2)->create();
        $dono = \App\Models\User::factory()->proprietario()->create();
        $ordemB = app(Entradas::class)->registrar($dono, $this->entrada($b), [$this->foto()]);
        $gerenteA = $this->usuario(Papel::Gerente, $a);

        $this->entrarComo($gerenteA)->get("/ordens/{$ordemB->numero}")->assertForbidden();
        $this->entrarComo($gerenteA)->get("/anexos/{$ordemB->anexos[0]->id}")->assertForbidden();
        $this->entrarComo($gerenteA)->get('/ordens')->assertOk()->assertDontSee($ordemB->numero);
        $this->entrarComo($gerenteA)->get("/ordens?unidade={$b->id}")->assertForbidden();
        $this->entrarComo($gerenteA)->post("/ordens/{$ordemB->numero}/cancelar", ['motivo' => 'tentativa direta'])->assertForbidden();
        $this->assertSame(StatusOrdem::Entrada, $ordemB->fresh()->status);

        $this->entrarComo($dono)->get("/anexos/{$ordemB->anexos[0]->id}")->assertOk();
    }

    public function test_fila_do_mecanico_e_assuncao_do_diagnostico(): void
    {
        $unidade = Unidade::factory()->create();
        $dono = \App\Models\User::factory()->proprietario()->create();
        $ordem = app(Entradas::class)->registrar($dono, $this->entrada($unidade));
        $m1 = $this->usuario(Papel::Mecanico, $unidade);
        $m2 = $this->usuario(Papel::Mecanico, $unidade);

        $this->entrarComo($m1)->get('/fila')->assertOk()->assertSee($ordem->numero);
        $this->entrarComo($m1)->post("/ordens/{$ordem->numero}/diagnostico/iniciar")->assertRedirect();
        $ordem->refresh();
        $this->assertSame(StatusOrdem::Diagnostico, $ordem->status);
        $this->assertSame($m1->id, $ordem->mecanico_id);

        // Outro mecânico não vê nem altera ordem assumida.
        $this->entrarComo($m2)->get("/ordens/{$ordem->numero}")->assertForbidden();
        $this->entrarComo($m2)->post("/ordens/{$ordem->numero}/diagnostico", ['diagnostico' => 'invasão'])->assertForbidden();

        $this->entrarComo($m1)->post("/ordens/{$ordem->numero}/diagnostico", ['diagnostico' => 'Pastilhas gastas'])->assertRedirect();
        $this->assertSame('Pastilhas gastas', $ordem->fresh()->diagnostico);
        // Mecânico não cancela ordem.
        $this->entrarComo($m1)->post("/ordens/{$ordem->numero}/cancelar", ['motivo' => 'não quero'])->assertForbidden();
    }

    public function test_transicoes_invalidas_sao_rejeitadas_e_cancelamento_exige_permissao_e_motivo(): void
    {
        $unidade = Unidade::factory()->create();
        $gerente = $this->usuario(Papel::Gerente, $unidade);
        $recepcao = $this->usuario(Papel::Recepcao, $unidade);
        $ordem = app(Entradas::class)->registrar($gerente, $this->entrada($unidade));
        $servico = app(Ordens::class);

        try {
            $servico->transicionar($ordem, StatusOrdem::Entregue, $gerente);
            $this->fail('transição entrada → entregue deveria falhar');
        } catch (ValidationException) {
        }
        $this->entrarComo($recepcao)->post("/ordens/{$ordem->numero}/cancelar", ['motivo' => 'Cliente desistiu'])->assertForbidden();
        $this->entrarComo($gerente)->post("/ordens/{$ordem->numero}/cancelar", ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->entrarComo($gerente)->post("/ordens/{$ordem->numero}/cancelar", ['motivo' => 'Cliente desistiu'])->assertRedirect();

        $ordem->refresh();
        $this->assertSame(StatusOrdem::Cancelado, $ordem->status);
        $this->assertSame('Cliente desistiu', $ordem->cancelamento_motivo);
        $this->expectException(ValidationException::class);
        $servico->iniciarDiagnostico($ordem, $gerente);
    }

    public function test_recepcao_com_permissao_concedida_pode_cancelar(): void
    {
        $unidade = Unidade::factory()->create();
        $recepcao = $this->usuario(Papel::Recepcao, $unidade);
        $recepcao->forceFill(['permissoes_extras' => ['cancelar_ordem']])->save();
        $ordem = app(Entradas::class)->registrar($recepcao, $this->entrada($unidade));
        $this->entrarComo($recepcao)->post("/ordens/{$ordem->numero}/cancelar", ['motivo' => 'Cliente desistiu'])->assertRedirect();
        $this->assertSame(StatusOrdem::Cancelado, $ordem->fresh()->status);
    }

    public function test_busca_de_clientes_e_unificacao_sem_perder_atendimentos(): void
    {
        $unidade = Unidade::factory()->create();
        $gerente = $this->usuario(Papel::Gerente, $unidade);
        $s = app(Entradas::class);
        $o1 = $s->registrar($gerente, $this->entrada($unidade, ['nome' => 'Ana Souza', 'placa' => 'AAA1111']));
        $o2 = $s->registrar($gerente, $this->entrada($unidade, ['nome' => 'Ana S.', 'placa' => 'BBB2222']));
        $this->assertNotSame($o1->cliente_id, $o2->cliente_id);

        $this->entrarComo($gerente)->get('/clientes?busca=BBB2')->assertOk()->assertSee('Ana S.')->assertDontSee('Ana Souza');
        $this->entrarComo($this->usuario(Papel::Recepcao, $unidade))->post("/clientes/{$o1->cliente_id}/mesclar", ['duplicado_id' => $o2->cliente_id, 'motivo' => 'mesma pessoa'])->assertForbidden();
        $this->entrarComo($gerente)->post("/clientes/{$o1->cliente_id}/mesclar", ['duplicado_id' => $o2->cliente_id, 'motivo' => 'Mesma pessoa'])->assertRedirect();

        $this->assertSame(2, Ordem::where('cliente_id', $o1->cliente_id)->count());
        $this->assertSame(2, Veiculo::where('cliente_id', $o1->cliente_id)->count());
        $this->assertSame($o1->cliente_id, Cliente::find($o2->cliente_id)->mesclado_em_id);
        $this->assertDatabaseHas('audit_events', ['acao' => 'cliente_mesclado', 'motivo' => 'Mesma pessoa']);
        $this->entrarComo($gerente)->get("/clientes/{$o2->cliente_id}")->assertRedirect("/clientes/{$o1->cliente_id}");
    }

    public function test_edicao_de_cliente_e_uso_de_cadastro_limitados_a_quem_atende(): void
    {
        [$a, $b] = Unidade::factory()->count(2)->create();
        $dono = \App\Models\User::factory()->proprietario()->create();
        $ob = app(Entradas::class)->registrar($dono, $this->entrada($b));
        $recA = $this->usuario(Papel::Recepcao, $a);
        $this->entrarComo($recA)->put("/clientes/{$ob->cliente_id}", ['nome' => 'X', 'telefone' => '61900001111'])->assertForbidden();
        $this->assertSame('61991234567', $ob->cliente->fresh()->telefone);

        // Sem telefone/placa correspondentes, o id não revela o cadastro.
        Livewire::actingAs($recA)->test(NovaEntrada::class)->set('telefone', '61911112222')->call('usarCliente', $ob->cliente_id)->assertStatus(404);
        Livewire::actingAs($recA)->test(NovaEntrada::class)->set('telefone', '61991234567')->call('usarCliente', $ob->cliente_id)->assertSet('nome', 'Mariana Costa');
    }

    /** 06/11/2026: cliente já cadastrado encontrado pelo nome na nova entrada; um só veículo já preenche a placa. */
    public function test_nova_entrada_busca_cliente_pelo_nome(): void
    {
        $a = Unidade::factory()->create();
        $o = app(Entradas::class)->registrar(\App\Models\User::factory()->proprietario()->create(), $this->entrada($a));
        $rec = $this->usuario(Papel::Recepcao, $a);
        Livewire::actingAs($rec)->test(NovaEntrada::class)->set('busca', 'ma')->assertDontSee('Usar este cadastro') // menos de 3 letras
            ->set('busca', 'xyzw')->assertSee('Nenhum cliente encontrado')
            ->set('busca', 'cost')->assertSee('Mariana Costa')->assertSee('Usar este cadastro')
            ->call('usarCliente', $o->cliente_id)->assertSet('cliente_id', $o->cliente_id)->assertSet('nome', 'Mariana Costa')
            ->assertSet('telefone', '61991234567')->assertSet('placa', $o->veiculo->placa)->assertSee('Usando o cadastro existente');
        // Erro humano: sem acento, fora de ordem, letras trocadas, grafia parecida.
        foreach (['mariana csota', 'COSTA mariana', 'marianna', 'Mariana Kosta'] as $digitado) {
            Livewire::actingAs($rec)->test(NovaEntrada::class)->set('busca', $digitado)->assertSee('Mariana Costa');
        }
        app(Entradas::class)->registrar($rec, $this->entrada($a, ['nome' => 'João Lucas Thiago', 'telefone' => '61988887777', 'placa' => 'JLT1A23']));
        foreach (['joao', 'lukas', 'tiago', 'jlt1a'] as $digitado) {
            Livewire::actingAs($rec)->test(NovaEntrada::class)->set('busca', $digitado)->assertSee('João Lucas Thiago')->assertDontSee('Mariana Costa');
        }
        // Id fora da busca digitada continua 404 (sem enumeração).
        Livewire::actingAs($rec)->test(NovaEntrada::class)->set('busca', 'xyzw')->call('usarCliente', $o->cliente_id)->assertStatus(404);
    }

    public function test_pagina_de_clientes_abre_sem_busca(): void
    {
        $this->entrarComo($this->usuario(Papel::Recepcao))->get('/clientes')->assertOk()->assertSee('Nenhum cliente atendido nas suas unidades ainda.');
    }

    public function test_banco_rejeita_status_invalido_e_cancelamento_sem_motivo(): void
    {
        $ordem = Ordem::factory()->create();
        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('ordens')->where('id', $ordem->id)->update(['status' => 'cancelado']);
    }

    public function test_painel_mostra_somente_ordens_abertas_da_unidade(): void
    {
        [$a, $b] = Unidade::factory()->count(2)->create();
        $dono = \App\Models\User::factory()->proprietario()->create();
        $oa = app(Entradas::class)->registrar($dono, $this->entrada($a));
        $ob = app(Entradas::class)->registrar($dono, $this->entrada($b, ['placa' => 'XYZ9876']));
        $this->entrarComo($this->usuario(Papel::Recepcao, $a))->get('/')->assertOk()->assertSee($oa->numero)->assertDontSee($ob->numero);
        $this->entrarComo($dono)->get('/')->assertOk()->assertSee($oa->numero)->assertSee($ob->numero);
        $this->entrarComo($this->usuario(Papel::Mecanico, $a))->get('/')->assertRedirect('/meu-painel');
    }

    /** 06/11/2026: placa nova → consulta (simulador fora de produção); placa já cadastrada → veículo e dono do sistema. */
    public function test_placa_puxa_dados_do_veiculo_e_do_cliente(): void
    {
        $a = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $a);
        $esperado = app(\App\Integracoes\Placa\SimuladorPlaca::class)->consultar('BRA2E19');

        // Placa nova: preenche pela consulta; o que for digitado é salvo no cadastro do veículo.
        $c = Livewire::actingAs($rec)->test(NovaEntrada::class)->set('placa', 'bra-2e19')
            ->assertSet('marca', $esperado['marca'])->assertSet('modelo', $esperado['modelo'])->assertSet('ano', (string) $esperado['ano'])
            ->assertSet('cor', $esperado['cor'])->assertSee('preenchidos pela consulta da placa')->assertSee('SIMULAÇÃO: dados fictícios');
        $c->set('cor', 'Azul')->set('nome', 'Paulo Reis')->set('telefone', '61977776666')->set('motivo', 'Revisão')->call('salvar')->assertHasNoErrors();
        $v = \App\Models\Veiculo::where('placa', 'BRA2E19')->sole();
        $this->assertSame([$esperado['marca'], $esperado['modelo'], $esperado['ano'], 'Azul'], [$v->marca, $v->modelo, $v->ano, $v->cor]);

        // Placa já no sistema: puxa veículo e cliente (nada da consulta).
        Livewire::actingAs($rec)->test(NovaEntrada::class)->set('placa', 'BRA2E19')
            ->assertSet('cliente_id', $v->cliente_id)->assertSet('nome', 'Paulo Reis')->assertSet('telefone', '61977776666')
            ->assertSet('cor', 'Azul')->assertSee('dados puxados do sistema');

        // Telefone de outro cliente já digitado: não troca o cliente (fluxo de transferência continua valendo).
        Livewire::actingAs($rec)->test(NovaEntrada::class)->set('telefone', '61911112222')->set('placa', 'BRA2E19')->assertSet('cliente_id', null);

        // Consulta sem resultado e troca de placa limpam o que veio da placa anterior.
        Livewire::actingAs($rec)->test(NovaEntrada::class)->set('placa', 'BRA2E19')->set('placa', 'QWE1R20')
            ->assertSet('marca', '')->assertSet('modelo', '')->assertSet('ano', '');

        // Produção sem provedor contratado: nada é consultado; a recepção digita.
        config(['oficina.integracoes' => 'real']);
        $this->assertNull(app(\App\Integracoes\Placa\ConsultaPlaca::class)->consultar('BRA2E19'));
    }

    /** Fonte não oficial (integrasist): mapeia a resposta, só usa marca/modelo/ano/cor; erro ou código ≠ 0 não bloqueia. */
    public function test_consulta_placa_integrasist(): void
    {
        config(['oficina.placa.provedor' => 'integrasist']);
        \Illuminate\Support\Facades\Http::fake([
            '*/sinesp/placa/PWR8F45' => \Illuminate\Support\Facades\Http::response(['ano' => '2015', 'anoModelo' => '2015', 'chassi' => '***96462', 'codigoRetorno' => '0', 'cor' => 'PRATA',
                'marca' => 'M.BENZ', 'mensagemRetorno' => 'Consulta efetuada com sucesso', 'modelo' => 'C180', 'municipio' => 'BRASILIA', 'placa' => 'PWR8F45', 'uf' => 'DF']),
            '*/sinesp/placa/ERR1A23' => \Illuminate\Support\Facades\Http::response(['codigoRetorno' => '3', 'mensagemRetorno' => 'Veículo não encontrado']),
            '*/sinesp/placa/OFF1A23' => \Illuminate\Support\Facades\Http::response('', 503),
        ]);
        $c = app(\App\Integracoes\Placa\ConsultaPlaca::class);
        $this->assertInstanceOf(\App\Integracoes\Placa\IntegrasistPlaca::class, $c);
        $this->assertSame(['marca' => 'M.BENZ', 'modelo' => 'C180', 'ano' => 2015, 'cor' => 'Prata'], $c->consultar('PWR8F45'));
        $this->assertSame(['TOYOTA', 'HILUX CDSRXA4FD'], \App\Integracoes\Placa\IntegrasistPlaca::marcaModelo('I/TOYOTA HILUX CDSRXA4FD', 'I/TOYOTA HILUX CDSRXA4FD'));
        $this->assertSame(['VW', 'GOL 1.0'], \App\Integracoes\Placa\IntegrasistPlaca::marcaModelo('VW/GOL 1.0', 'VW/GOL 1.0'));
        $this->assertSame(['M.BENZ', 'C180'], \App\Integracoes\Placa\IntegrasistPlaca::marcaModelo('M.BENZ', 'C180'));
        $this->assertNull($c->consultar('ERR1A23'));
        $this->assertNull($c->consultar('OFF1A23'));
        $this->assertNull($c->consultar('../x'));
        $c->consultar('PWR8F45'); // guardado: não consulta de novo
        \Illuminate\Support\Facades\Http::assertSentCount(3);

        $rec = $this->usuario(Papel::Recepcao, Unidade::factory()->create());
        Livewire::actingAs($rec)->test(NovaEntrada::class)->set('placa', 'pwr8f45')->assertSet('modelo', 'C180')->assertSet('ano', '2015')
            ->assertSee('preenchidos pela consulta da placa')->assertDontSee('SIMULAÇÃO');
    }
}
