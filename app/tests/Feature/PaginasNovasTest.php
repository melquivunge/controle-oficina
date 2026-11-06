<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Http\Controllers\AuditoriaController;
use App\Integracoes\Mensagens\Mensageiro;
use App\Integracoes\Mensagens\SimuladorMensagens;
use App\Jobs\EnviarMensagem;
use App\Models\Divergencia;
use App\Models\Entrega;
use App\Models\MessageDelivery;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\Conferencias;
use App\Services\Entradas;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaginasNovasTest extends TestCase
{
    private Unidade $a;
    private Unidade $b;
    private User $dono;
    private User $gerA;
    private User $recA;
    private User $mecA;
    private User $gerB;
    private User $recB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado']);
        [$this->a, $this->b] = Unidade::factory()->count(2)->create();
        $this->dono = User::factory()->proprietario()->create();
        $this->gerA = $this->usuario(Papel::Gerente, $this->a);
        $this->recA = $this->usuario(Papel::Recepcao, $this->a);
        $this->mecA = $this->usuario(Papel::Mecanico, $this->a);
        $this->gerB = $this->usuario(Papel::Gerente, $this->b);
        $this->recB = $this->usuario(Papel::Recepcao, $this->b);
    }

    private function entrada(User $rec, Unidade $u, string $placa, string $telefone = '61991234567'): Ordem
    {
        return app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'Cliente '.$placa, 'telefone' => $telefone, 'placa' => $placa, 'motivo' => 'barulho']);
    }

    private function orcamentoEnviado(Ordem $o, User $rec, string $preco = '300'): \App\Models\Orcamento
    {
        $ger = $this->usuario(Papel::Gerente, $o->unidade); // só gerente/proprietário orçam
        app(Ordens::class)->iniciarDiagnostico($o, $this->usuario(Papel::Mecanico, $o->unidade));
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $ger, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => $preco]]);
        $s->enviar($v, $ger);

        return $v->fresh();
    }

    private function entregaExcecao(Ordem $o, User $ger, string $motivo): Entrega
    {
        return Entrega::create(['ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'entregue_por' => $ger->id, 'saldo_centavos' => 10000,
            'excecao' => true, 'excecao_motivo' => $motivo, 'autorizado_por' => $ger->id, 'entregue_em' => now()]);
    }

    private function mensagemFalhou(Ordem $o, string $destino = '61988880000'): MessageDelivery
    {
        return MessageDelivery::create(['ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'tipo' => 'conclusao', 'canal' => 'simulado', 'destino' => $destino,
            'conteudo' => 'texto', 'status' => 'falhou', 'tentativas' => 4, 'ultimo_erro' => 'Simulação: provedor de mensagens indisponível.']);
    }

    public function test_veiculos_busca_placa_parcial_normalizada_e_respeita_unidade(): void
    {
        $oa = $this->entrada($this->recA, $this->a, 'AAA1B23');
        $this->entrada($this->recB, $this->b, 'BBB2C34');

        $this->entrarComo($this->recA)->get('/veiculos')->assertOk()->assertSee('AAA1B23')->assertSee($oa->numero)->assertDontSee('BBB2C34');
        $this->entrarComo($this->recA)->get('/veiculos?placa=aaa-1b')->assertOk()->assertSee('AAA1B23');
        $this->entrarComo($this->recA)->get('/veiculos?placa=bbb2')->assertOk()->assertDontSee('BBB2C34')->assertSee('Nenhum veículo');
        $this->entrarComo($this->dono)->get('/veiculos')->assertOk()->assertSee('AAA1B23')->assertSee('BBB2C34');
        $this->entrarComo($this->mecA)->get('/veiculos')->assertForbidden();
    }

    public function test_orcamentos_lista_global_filtra_status_e_unidade_sem_rascunho(): void
    {
        $oa = $this->entrada($this->recA, $this->a, 'AAA1B23');
        $va = $this->orcamentoEnviado($oa, $this->recA, '639');
        $ob = $this->entrada($this->recB, $this->b, 'BBB2C34');
        $this->orcamentoEnviado($ob, $this->recB);
        $rascunho = $this->entrada($this->recA, $this->a, 'CCC3D45');
        app(Ordens::class)->iniciarDiagnostico($rascunho, $this->mecA);
        app(Orcamentos::class)->salvarRascunho($rascunho, $this->gerA, [['descricao' => 'x', 'quantidade' => '1', 'preco' => '10']]);

        $this->entrarComo($this->gerA)->get('/orcamentos')->assertOk()
            ->assertSee($oa->numero.' · v'.$va->versao)->assertSee('R$ 639,00', false)
            ->assertSee(route('ordens.show', [$oa, 'aba' => 'orcamento']), false)
            ->assertDontSee($ob->numero)->assertDontSee($rascunho->numero);
        $this->entrarComo($this->gerA)->get('/orcamentos?status=aprovado')->assertOk()->assertDontSee($oa->numero);
        $this->entrarComo($this->gerA)->get('/orcamentos?status=todos')->assertOk()->assertSee($oa->numero)->assertDontSee($rascunho->numero);
        $this->entrarComo($this->gerA)->get("/orcamentos?unidade={$this->b->id}")->assertForbidden();
        $this->entrarComo($this->gerA)->get('/orcamentos?status=rascunho')->assertSessionHasErrors('status');
        $this->entrarComo($this->dono)->get("/orcamentos?unidade={$this->b->id}")->assertOk()->assertSee($ob->numero)->assertDontSee($oa->numero);
        $this->entrarComo($this->mecA)->get('/orcamentos')->assertForbidden();
        $this->entrarComo($this->recA)->get('/orcamentos')->assertOk()->assertDontSee('Aprovar orçamento'); // recepção consulta (decisão 30/10), não orça
    }

    public function test_pendencias_listam_por_unidade_e_resolucao_exige_texto_e_gera_auditoria(): void
    {
        $oa = $this->entrada($this->recA, $this->a, 'AAA1B23');
        $ob = $this->entrada($this->recB, $this->b, 'BBB2C34');
        $ea = $this->entregaExcecao($oa, $this->gerA, 'Cliente frotista com faturamento');
        $eb = $this->entregaExcecao($ob, $this->gerB, 'Motivo da outra unidade');
        $op = $this->entrada($this->recA, $this->a, 'DDD4E56');
        Pagamento::create(['ordem_id' => $op->id, 'unidade_id' => $this->a->id, 'meio' => 'dinheiro', 'valor_centavos' => 5000, 'status' => 'em_revisao',
            'motivo_revisao' => 'valor diferente do cobrado', 'recebido_em' => now()->subHour(), 'registrado_por' => $this->recA->id]);

        $this->entrarComo($this->recA)->get('/pendencias')->assertForbidden();
        $this->entrarComo($this->gerA)->get('/pendencias')->assertOk()
            ->assertSee('Entrega sem quitação')->assertSee('Cliente frotista com faturamento')->assertSee('Crítico')
            ->assertSee('Pagamento em revisão')->assertSee('valor diferente do cobrado')
            ->assertDontSee('Motivo da outra unidade');

        // Outra unidade: 403 e nada gravado.
        $this->entrarComo($this->gerA)->post("/pendencias/entrega_excecao/{$eb->id}/resolver", ['resolucao' => 'tentativa indevida'])->assertForbidden();
        // Pagamento em revisão não se resolve aqui (aceitar/devolver continua na OS).
        $this->entrarComo($this->gerA)->post('/pendencias/pagamento_revisao/1/resolver', ['resolucao' => 'qualquer coisa'])->assertNotFound();
        $this->entrarComo($this->gerA)->post("/pendencias/entrega_excecao/{$ea->id}/resolver", ['resolucao' => ''])->assertSessionHasErrors('resolucao');
        $this->assertDatabaseCount('resolucoes_pendencias', 0);

        $this->entrarComo($this->gerA)->post("/pendencias/entrega_excecao/{$ea->id}/resolver", ['resolucao' => 'Faturamento confirmado com o financeiro'])->assertRedirect()->assertSessionHas('sucesso');
        $this->assertDatabaseHas('resolucoes_pendencias', ['tipo' => 'entrega_excecao', 'referencia_id' => $ea->id, 'resolvido_por' => $this->gerA->id, 'unidade_id' => $this->a->id]);
        $this->assertDatabaseHas('audit_events', ['acao' => 'pendencia_resolvida', 'ator_id' => $this->gerA->id, 'entidade_tipo' => 'ordem', 'entidade_id' => $oa->id, 'motivo' => 'Faturamento confirmado com o financeiro']);
        $this->entrarComo($this->gerA)->post("/pendencias/entrega_excecao/{$ea->id}/resolver", ['resolucao' => 'de novo, duplicado'])->assertSessionHasErrors('resolucao');

        $this->entrarComo($this->gerA)->get('/pendencias')->assertOk()->assertDontSee('Cliente frotista com faturamento');
        $this->entrarComo($this->gerA)->get('/pendencias?resolvidas=1')->assertOk()->assertSee('Cliente frotista com faturamento')->assertSee('Faturamento confirmado com o financeiro');
        $this->entrarComo($this->dono)->get('/pendencias')->assertOk()->assertSee('Motivo da outra unidade');
    }

    public function test_divergencia_resolvida_pela_pagina_de_pendencias_reutiliza_conferencia(): void
    {
        $this->entrada($this->recA, $this->a, 'AAA1B23');
        app(Conferencias::class)->registrar($this->a->id, $this->recA, [], []);
        $d = Divergencia::sole();
        $this->entrarComo($this->gerA)->get('/pendencias')->assertOk()->assertSee('Divergência na conferência');
        $this->entrarComo($this->gerB)->post("/pendencias/divergencia/{$d->id}/resolver", ['resolucao' => 'outra unidade tentando'])->assertForbidden();
        $this->entrarComo($this->gerA)->post("/pendencias/divergencia/{$d->id}/resolver", ['resolucao' => 'Veículo estava no lava-jato'])->assertRedirect();
        $this->assertSame($this->gerA->id, $d->fresh()->resolvido_por);
        $this->assertDatabaseHas('audit_events', ['acao' => 'divergencia_resolvida', 'ator_id' => $this->gerA->id]);
    }

    public function test_mensagens_mascaram_destino_e_reenvio_so_na_fila_simulada_da_propria_unidade(): void
    {
        $this->assertInstanceOf(SimuladorMensagens::class, app(Mensageiro::class), 'modo simulado nunca usa provedor real');
        $oa = $this->entrada($this->recA, $this->a, 'AAA1B23');
        $ob = $this->entrada($this->recB, $this->b, 'BBB2C34');
        $ma = $this->mensagemFalhou($oa, '61988880000');
        $mb = $this->mensagemFalhou($ob, '61977770000');

        $this->entrarComo($this->gerA)->get('/pendencias?aba=mensagens')->assertOk()
            ->assertSee('SIMULADAS e nunca enviadas')->assertSee('*****-0000')->assertSee('4/4')->assertSee('Tentar de novo')
            ->assertDontSee('61988880000')->assertDontSee($ob->numero);
        $this->entrarComo($this->gerA)->get('/pendencias')->assertOk()->assertSee('Mensagem não enviada');

        Queue::fake();
        $this->entrarComo($this->recA)->post("/pendencias/mensagens/{$ma->id}/reenviar")->assertForbidden();
        $this->entrarComo($this->gerA)->post("/pendencias/mensagens/{$mb->id}/reenviar")->assertForbidden();
        $this->assertSame('falhou', $mb->fresh()->status);
        $this->entrarComo($this->gerA)->post("/pendencias/mensagens/{$ma->id}/reenviar")->assertRedirect();
        $this->assertSame('pendente', $ma->fresh()->status);
        Queue::assertPushed(EnviarMensagem::class, fn ($j) => $j->mensagemId === $ma->id);
        Queue::assertNotPushed(EnviarMensagem::class, fn ($j) => $j->mensagemId === $mb->id);
        $this->assertDatabaseHas('audit_events', ['acao' => 'mensagem_reenviada', 'ator_id' => $this->gerA->id]);
        // Não é mais falha: só pode reenviar o que falhou.
        $this->entrarComo($this->gerA)->post("/pendencias/mensagens/{$ma->id}/reenviar")->assertNotFound();
    }

    public function test_processar_fila_redespacha_somente_pendentes_paradas_das_unidades_do_usuario(): void
    {
        $oa = $this->entrada($this->recA, $this->a, 'AAA1B23');
        $ob = $this->entrada($this->recB, $this->b, 'BBB2C34');
        $nova = fn (Ordem $o) => MessageDelivery::create(['ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'tipo' => 'entrada', 'canal' => 'simulado', 'destino' => '61991234567', 'conteudo' => 't']);
        [$paradaA, $recenteA, $paradaB] = [$nova($oa), $nova($oa), $nova($ob)];
        DB::table('message_deliveries')->whereIn('id', [$paradaA->id, $paradaB->id])->update(['updated_at' => now()->subMinutes(10)]);

        Queue::fake();
        $this->entrarComo($this->gerA)->post('/pendencias/mensagens/processar')->assertRedirect()->assertSessionHas('sucesso');
        Queue::assertPushed(EnviarMensagem::class, 1);
        Queue::assertPushed(EnviarMensagem::class, fn ($j) => $j->mensagemId === $paradaA->id);
        $this->assertDatabaseHas('audit_events', ['acao' => 'fila_mensagens_processada', 'ator_id' => $this->gerA->id]);
        $this->entrarComo($this->recA)->post('/pendencias/mensagens/processar')->assertForbidden();
        $this->assertNotNull($recenteA);
    }

    public function test_integracoes_mostram_modo_sem_segredos_e_so_para_quem_configura(): void
    {
        config(['oficina.pix.webhook_segredo' => 'segredo-super-secreto-123']);
        $this->entrarComo($this->dono)->get('/integracoes')->assertOk()
            ->assertSee('Pix')->assertSee('Mensagens (WhatsApp/SMS)')->assertSee('Fiscal (NFS-e / NF-e)')->assertSee('Simulado')
            ->assertSee('definido no servidor')->assertDontSee('segredo-super-secreto-123');
        config(['oficina.integracoes' => 'real']);
        $this->entrarComo($this->dono)->get('/integracoes')->assertOk()->assertSee('Real indisponível')->assertDontSee('SIMULADAS');
        $this->entrarComo($this->gerA)->get('/integracoes')->assertForbidden();
        $this->entrarComo($this->recA)->get('/integracoes')->assertForbidden();
    }

    public function test_auditoria_exporta_csv_com_filtros_unidade_e_escape_de_formulas(): void
    {
        Auditoria::registrar('teste_exportacao', motivo: '=HYPERLINK("http://x","clique")', unidadeId: $this->a->id, ator: $this->gerA);
        Auditoria::registrar('teste_exportacao', motivo: '+SOMA(1;2)', unidadeId: $this->a->id, ator: $this->gerA);
        Auditoria::registrar('teste_exportacao', motivo: 'evento da unidade B', unidadeId: $this->b->id, ator: $this->gerB);
        Auditoria::registrar('outra_acao', motivo: 'fora do filtro', unidadeId: $this->a->id, ator: $this->gerA);

        $r = $this->entrarComo($this->gerA)->get('/auditoria/exportar?acao=teste_exportacao');
        $r->assertOk();
        $this->assertStringContainsString('text/csv', $r->headers->get('Content-Type'));
        $csv = $r->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+SOMA", $csv);
        $this->assertStringNotContainsString(';=HYPERLINK', $csv);
        $this->assertStringNotContainsString('evento da unidade B', $csv);
        $this->assertStringNotContainsString('fora do filtro', $csv);
        $this->assertDatabaseHas('audit_events', ['acao' => 'auditoria_exportada', 'ator_id' => $this->gerA->id]);

        $this->entrarComo($this->gerA)->get("/auditoria/exportar?unidade={$this->b->id}")->assertForbidden();
        $this->entrarComo($this->recA)->get('/auditoria/exportar')->assertForbidden();
        $this->assertStringContainsString('evento da unidade B', $this->entrarComo($this->dono)->get('/auditoria/exportar')->streamedContent());
        $this->entrarComo($this->gerA)->get('/auditoria')->assertOk()->assertSee('Exportar CSV');

        $this->assertSame("'-1", AuditoriaController::celula('-1'));
        $this->assertSame("'@cmd", AuditoriaController::celula('@cmd'));
        $this->assertSame('texto', AuditoriaController::celula('texto'));
    }
}
