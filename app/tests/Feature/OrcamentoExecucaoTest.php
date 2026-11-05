<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Models\Aprovacao;
use App\Models\AuditEvent;
use App\Models\LinkCliente;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Execucao;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrcamentoExecucaoTest extends TestCase
{
    private Unidade $unidade;
    private User $gerente;
    private User $recepcao;
    private User $mecanico;
    private Ordem $ordem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unidade = Unidade::factory()->create(['codigo' => 'ASN']);
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
        $this->ordem = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana Costa', 'telefone' => '61991234567', 'placa' => 'ABC1D23', 'motivo' => 'Barulho']);
        app(Ordens::class)->iniciarDiagnostico($this->ordem, $this->mecanico);
    }

    private function itensPadrao(): array
    {
        return [['descricao' => 'Substituição de bieleta dianteira', 'quantidade' => '2', 'preco' => '180,00'], ['descricao' => 'Verificação da suspensão', 'quantidade' => '1', 'preco' => '120']];
    }

    private function enviarV1(): array
    {
        $s = app(Orcamentos::class);
        $v1 = $s->salvarRascunho($this->ordem, $this->gerente, $this->itensPadrao());
        $token = $s->enviar($v1, $this->gerente);

        return [$v1->fresh(), $token];
    }

    public function test_totais_calculados_no_servidor_e_envio_gera_link_com_hash(): void
    {
        $s = app(Orcamentos::class);
        $v1 = $s->salvarRascunho($this->ordem, $this->gerente, [...$this->itensPadrao(), ['descricao' => 'Graxa', 'quantidade' => '2,5', 'preco' => '33,33']]);
        $this->assertSame(36000 + 12000 + 8333, $v1->total_centavos); // 2,5 × 33,33 = 83,325 → 83,33
        $this->assertSame('rascunho', $v1->status);
        $this->assertSame(1, $v1->versao);

        $token = $s->enviar($v1, $this->gerente);
        $this->assertSame(48, strlen($token));
        $this->assertDatabaseHas('links_cliente', ['ordem_id' => $this->ordem->id, 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('links_cliente', ['token_hash' => $token]);
        $this->assertSame(StatusOrdem::AguardandoAprovacao, $this->ordem->fresh()->status);
        $this->assertSame('enviado', $v1->fresh()->status);
    }

    public function test_itens_de_versao_enviada_sao_imutaveis_no_banco(): void
    {
        [$v1] = $this->enviarV1();
        $this->expectException(QueryException::class);
        DB::table('orcamento_itens')->where('orcamento_id', $v1->id)->update(['preco_unitario_centavos' => 1]);
    }

    public function test_desconto_exige_permissao_motivo_e_limite(): void
    {
        $s = app(Orcamentos::class);
        try {
            $s->salvarRascunho($this->ordem, $this->recepcao, $this->itensPadrao(), 1000, 'cliente antigo');
            $this->fail('recepção não orça (nem dá desconto)');
        } catch (AuthorizationException) {
        }
        try {
            $s->salvarRascunho($this->ordem, $this->gerente, $this->itensPadrao(), 1000);
            $this->fail('desconto sem motivo');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('desconto_motivo', $e->errors());
        }
        try {
            $s->salvarRascunho($this->ordem, $this->gerente, $this->itensPadrao(), 48001, 'erro');
            $this->fail('desconto maior que subtotal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('desconto', $e->errors());
        }
        $v = $s->salvarRascunho($this->ordem, $this->gerente, $this->itensPadrao(), 2000, 'Cliente antigo');
        $this->assertSame([48000, 2000, 46000], [$v->subtotal_centavos, $v->desconto_centavos, $v->total_centavos]);
        $this->assertDatabaseHas('audit_events', ['acao' => 'orcamento_rascunho', 'motivo' => 'Cliente antigo']);
    }

    public function test_validacao_de_itens_aponta_linha_e_campo(): void
    {
        try {
            app(Orcamentos::class)->salvarRascunho($this->ordem, $this->gerente, [['descricao' => 'Peça', 'quantidade' => '0', 'preco' => 'abc']]);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('itens.0.quantidade', $e->errors());
            $this->assertArrayHasKey('itens.0.preco', $e->errors());
        }
    }

    public function test_cliente_aprova_pelo_link_mesmo_com_funcionario_logado_e_auditoria_registra_cliente(): void
    {
        [$v1, $token] = $this->enviarV1();
        $this->get("/c/{$token}")->assertOk()->assertSee('R$ 480,00')->assertSee('Substituição de bieleta dianteira')
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('61991234567');

        $this->actingAs($this->recepcao)->post("/c/{$token}/decisao", ['orcamento_id' => $v1->id, 'decisao' => 'aprovado'])->assertRedirect("/c/{$token}");
        $this->assertSame('aprovado', $v1->fresh()->status);
        $this->assertSame(StatusOrdem::Autorizado, $this->ordem->fresh()->status);
        $evento = AuditEvent::where('acao', 'orcamento_aprovado')->sole();
        $this->assertNull($evento->ator_id);
        $this->assertSame('cliente', $evento->ator_tipo);
        $this->assertSame(48000, Aprovacao::sole()->total_centavos);

        // Repetição do envio não duplica aprovação.
        $this->post("/c/{$token}/decisao", ['orcamento_id' => $v1->id, 'decisao' => 'aprovado'])->assertSessionHasErrors('decisao');
        $this->assertSame(1, Aprovacao::count());
    }

    public function test_link_invalido_expirado_revogado_ou_de_outra_ordem_nao_vaza_dados(): void
    {
        [$v1, $token] = $this->enviarV1();
        $this->get('/c/'.str_repeat('a', 48))->assertNotFound();
        $this->get('/c/curto')->assertNotFound();

        // Token de outra ordem não decide esta versão.
        $outra = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Outro', 'telefone' => '61999990001', 'placa' => 'XYZ9876', 'motivo' => 'Revisão']);
        $tokenOutra = app(Orcamentos::class)->novoLink($outra, $this->recepcao);
        $this->post("/c/{$tokenOutra}/decisao", ['orcamento_id' => $v1->id, 'decisao' => 'aprovado'])->assertSessionHasErrors('decisao');
        $this->get("/c/{$tokenOutra}")->assertOk()->assertDontSee('Mariana')->assertDontSee('R$ 480,00');
        $this->assertSame('enviado', $v1->fresh()->status);

        LinkCliente::where('token_hash', hash('sha256', $token))->update(['expira_em' => now()->subMinute()]);
        $this->get("/c/{$token}")->assertNotFound();
        LinkCliente::where('token_hash', hash('sha256', $token))->update(['expira_em' => now()->addDay()]);
        $this->entrarComo($this->recepcao)->post("/ordens/{$this->ordem->numero}/link/revogar")->assertRedirect();
        $this->get("/c/{$token}")->assertNotFound();
    }

    public function test_versao_antiga_nao_pode_ser_aprovada_depois_de_nova_versao(): void
    {
        [$v1, $token] = $this->enviarV1();
        $s = app(Orcamentos::class);
        $v2 = $s->salvarRascunho($this->ordem, $this->gerente, [['descricao' => 'Só a bieleta', 'quantidade' => '2', 'preco' => '180']]);
        $token2 = $s->enviar($v2, $this->gerente);

        // Link antigo continua abrindo, mas mostra a versão atual; decisão sobre a versão antiga é rejeitada.
        $this->get("/c/{$token}")->assertOk()->assertSee('versão 2')->assertDontSee('R$ 480,00');
        $this->post("/c/{$token2}/decisao", ['orcamento_id' => $v1->id, 'decisao' => 'aprovado'])->assertSessionHasErrors('decisao');
        $this->assertSame('substituido', $v1->fresh()->status);
        $this->post("/c/{$token2}/decisao", ['orcamento_id' => $v2->id, 'decisao' => 'aprovado'])->assertRedirect();
        $this->assertSame(36000, Orcamento::where('status', 'aprovado')->sole()->total_centavos);
    }

    public function test_recusa_volta_ao_diagnostico_e_nao_autoriza_execucao(): void
    {
        [$v1, $token] = $this->enviarV1();
        $this->post("/c/{$token}/decisao", ['orcamento_id' => $v1->id, 'decisao' => 'recusado', 'observacao' => 'Caro'])->assertRedirect();
        $this->assertSame(StatusOrdem::Diagnostico, $this->ordem->fresh()->status);
        $this->expectException(ValidationException::class);
        app(Execucao::class)->iniciar($this->ordem->fresh(), $this->mecanico);
    }

    public function test_aprovacao_presencial_exige_permissao_e_registra_responsavel(): void
    {
        [$v1] = $this->enviarV1();
        $this->entrarComo($this->mecanico)->post("/ordens/{$this->ordem->numero}/orcamentos/{$v1->id}/presencial", ['decisao' => 'aprovado', 'observacao' => 'ligação'])->assertForbidden();
        $this->entrarComo($this->recepcao)->post("/ordens/{$this->ordem->numero}/orcamentos/{$v1->id}/presencial", ['decisao' => 'aprovado', 'observacao' => 'Ligação às 10h com a cliente'])->assertRedirect();
        $a = Aprovacao::sole();
        $this->assertSame(['presencial', $this->recepcao->id], [$a->canal, $a->registrado_por]);
    }

    public function test_execucao_somente_de_itens_aprovados_extras_aguardam_nova_aprovacao(): void
    {
        [$v1, $token] = $this->enviarV1();
        $exec = app(Execucao::class);
        $s = app(Orcamentos::class);
        $s->decidir($v1->id, $this->ordem->id, 'aprovado', 'link_cliente');

        $this->entrarComo($this->recepcao)->post("/ordens/{$this->ordem->numero}/execucao/iniciar")->assertForbidden();
        $exec->iniciar($this->ordem, $this->mecanico);
        $itensV1 = $v1->fresh()->itens;
        $exec->concluirItem($this->ordem, $itensV1[0]->id, $this->mecanico);

        // Extra durante a execução: nova versão marcada como extra; ordem segue em execução.
        $v2 = $s->salvarRascunho($this->ordem, $this->gerente, [...$this->itensPadrao(), ['descricao' => 'Pastilha de freio', 'quantidade' => '1', 'preco' => '150']]);
        $this->assertSame([false, false, true], $v2->itens->pluck('extra')->all());
        $s->enviar($v2, $this->gerente);
        $this->assertSame(StatusOrdem::EmExecucao, $this->ordem->fresh()->status);

        $extra = $v2->fresh()->itens[2];
        try {
            $exec->concluirItem($this->ordem, $extra->id, $this->mecanico);
            $this->fail('extra não aprovado não pode ser concluído');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('não está autorizado', $e->getMessage());
        }
        $exec->concluirItem($this->ordem, $itensV1[1]->id, $this->mecanico);
        try {
            $exec->marcarPronto($this->ordem, $this->mecanico);
            $this->fail('não pode liberar com versão pendente');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('aguardando decisão', $e->getMessage());
        }

        $s->decidir($v2->id, $this->ordem->id, 'aprovado', 'link_cliente');
        $v2 = $v2->fresh('itens');
        $this->assertSame('substituido', $v1->fresh()->status);
        $this->assertNotNull($v2->itens[0]->concluido_em, 'conclusões herdadas da versão anterior');
        $this->assertNull($v2->itens[2]->concluido_em);
        try {
            $exec->marcarPronto($this->ordem, $this->mecanico);
            $this->fail('extra aprovado ainda não concluído');
        } catch (ValidationException) {
        }
        $exec->concluirItem($this->ordem, $v2->itens[2]->id, $this->mecanico);
        $exec->marcarPronto($this->ordem, $this->mecanico, 'Teste de rodagem ok');
        $this->assertSame(StatusOrdem::ProntoParaEntrega, $this->ordem->fresh()->status);
    }

    public function test_extra_recusado_mantem_versao_anterior_autorizada(): void
    {
        [$v1] = $this->enviarV1();
        $s = app(Orcamentos::class);
        $s->decidir($v1->id, $this->ordem->id, 'aprovado', 'link_cliente');
        app(Execucao::class)->iniciar($this->ordem, $this->mecanico);
        $v2 = $s->salvarRascunho($this->ordem, $this->gerente, [...$this->itensPadrao(), ['descricao' => 'Extra', 'quantidade' => '1', 'preco' => '10']]);
        $s->enviar($v2, $this->gerente);
        $s->decidir($v2->id, $this->ordem->id, 'recusado', 'link_cliente');
        $this->assertSame('aprovado', $v1->fresh()->status);
        $this->assertSame(48000, $this->ordem->fresh()->orcamentoAprovado->total_centavos);
    }

    public function test_mecanico_nao_orca_e_outra_unidade_nao_acessa(): void
    {
        $this->expectException(AuthorizationException::class);
        try {
            $this->entrarComo($this->usuario(Papel::Gerente))->post("/ordens/{$this->ordem->numero}/link")->assertForbidden();
        } finally {
            app(Orcamentos::class)->salvarRascunho($this->ordem, $this->mecanico, $this->itensPadrao());
        }
    }
}
