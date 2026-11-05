<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Integracoes\Pix\ProvedorIndisponivel;
use App\Integracoes\Pix\ProvedorPix;
use App\Integracoes\Pix\SimuladorPix;
use App\Models\Devolucao;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\Recebedor;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Indicadores;
use App\Services\Orcamentos;
use App\Services\Ordens;
use App\Services\Pendencias;
use App\Support\Fuso;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Devolução Pix solicitada ao provedor (simulado): processando → concluida | falhou, idempotência e pendência. */
class DevolucaoPixTest extends TestCase
{
    private Unidade $unidade;
    private User $gerente;
    private User $recepcao;
    private User $mecanico;
    private Ordem $ordem;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado', 'oficina.pix.simular_falha_devolucao' => false]);
        $this->unidade = Unidade::factory()->create(['codigo' => 'ASN']);
        $dono = User::factory()->proprietario()->create();
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
        Recebedor::create(['unidade_id' => $this->unidade->id, 'provedor' => 'simulado', 'identificador_conta' => 'conta-asn', 'descricao' => 'Conta PJ', 'ativo' => true, 'criado_por' => $dono->id]);
        $this->ordem = $this->ordemAprovada(48000);
    }

    private function ordemAprovada(int $centavos, string $placa = 'ABC1D23'): Ordem
    {
        $o = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana', 'telefone' => '61991234567', 'placa' => $placa, 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->mecanico);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $this->gerente, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => (string) ($centavos / 100)]]);
        $s->enviar($v, $this->gerente);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');

        return $o->fresh();
    }

    private function pixConfirmado(?int $pago = null): Pagamento
    {
        $f = app(Financeiro::class);
        $c = $f->gerarCobranca($this->ordem, $this->recepcao);
        app(SimuladorPix::class)->simularPagamento($c->txid, $pago);
        $f->conciliarCobranca($c->txid);

        return Pagamento::where('cobranca_id', $c->id)->firstOrFail();
    }

    private function tiposPendentes(): array
    {
        return app(Pendencias::class)->listar($this->gerente)->pluck('tipo')->all();
    }

    public function test_devolucao_pix_concluida_pelo_provedor_abate_saldo_e_audita(): void
    {
        $p = $this->pixConfirmado();
        $this->assertSame(0, app(Financeiro::class)->resumo($this->ordem)['saldo']);

        $this->entrarComo($this->gerente)->post("/ordens/{$this->ordem->numero}/pagamentos/{$p->id}/devolver", ['valor_devolucao' => '50,00', 'motivo' => 'Peça não utilizada'])
            ->assertRedirect()->assertSessionHas('sucesso', 'Devolução registrada.');

        $d = Devolucao::sole();
        $this->assertSame('concluida', $d->status);
        $this->assertStringStartsWith('D', $d->id_provedor);
        $this->assertNotNull($d->concluida_em);
        $this->assertSame(5000, app(Financeiro::class)->resumo($this->ordem)['saldo']);
        $this->assertDatabaseHas('audit_events', ['acao' => 'devolucao_solicitada', 'ator_id' => $this->gerente->id, 'motivo' => 'Peça não utilizada']);
        $this->assertDatabaseHas('audit_events', ['acao' => 'devolucao_concluida', 'ator_tipo' => 'sistema']);
        $this->assertSame(1, DB::table('simulador_pix_devolucoes')->where('status', 'devolvida')->count());
    }

    public function test_falha_nao_abate_aparece_em_pendencias_e_nova_tentativa_conclui_e_some(): void
    {
        $p = $this->pixConfirmado();
        config(['oficina.pix.simular_falha_devolucao' => true]);
        $this->entrarComo($this->gerente)->post("/ordens/{$this->ordem->numero}/pagamentos/{$p->id}/devolver", ['valor_devolucao' => '50,00', 'motivo' => 'Peça não utilizada'])
            ->assertRedirect()->assertSessionHas('erro_cobranca');

        $d = Devolucao::sole();
        $this->assertSame('falhou', $d->status);
        $this->assertStringContainsString('recusou', $d->mensagem);
        $this->assertNull($d->concluida_em);
        $this->assertSame(0, app(Financeiro::class)->resumo($this->ordem)['saldo'], 'falha não abate o recebido');
        $ind = app(Indicadores::class)->porUnidade([$this->unidade->id], Fuso::inicioDoDia(Fuso::exibir(now(), 'Y-m-d')), now()->addDay()->toImmutable());
        $this->assertSame(0, $ind[$this->unidade->id]['devolvido']);
        $this->assertSame(48000, $ind[$this->unidade->id]['recebido']);
        $this->assertGreaterThanOrEqual(1, $ind[$this->unidade->id]['falhas']);
        $this->assertDatabaseHas('audit_events', ['acao' => 'devolucao_falhou', 'ator_tipo' => 'sistema']);

        $item = app(Pendencias::class)->listar($this->gerente)->firstWhere('tipo', 'devolucao_falhou');
        $this->assertNotNull($item);
        $this->assertSame('atencao', $item['gravidade']);
        $this->assertStringContainsString('saldo insuficiente', $item['descricao']);
        $this->entrarComo($this->gerente)->get('/pendencias')->assertOk()->assertSee('Devolução falhou: provedor recusou')
            ->assertSee(route('ordens.show', [$this->ordem->numero, 'aba' => 'pagamentos']), false);
        $this->entrarComo($this->gerente)->get("/ordens/{$this->ordem->numero}?aba=pagamentos")->assertOk()
            ->assertSee('Falhou')->assertSee('Tentar de novo')->assertSee('saldo insuficiente');

        // Nova tentativa com o provedor aceitando: mesma chave, conclui e sai das pendências.
        config(['oficina.pix.simular_falha_devolucao' => false]);
        $this->entrarComo($this->gerente)->post(route('devolucoes.tentar', [$this->ordem, $d]))->assertRedirect()->assertSessionHas('sucesso');
        $d->refresh();
        $this->assertSame('concluida', $d->status);
        $this->assertNull($d->mensagem);
        $this->assertSame(5000, app(Financeiro::class)->resumo($this->ordem)['saldo']);
        $this->assertNotContains('devolucao_falhou', $this->tiposPendentes());
        $sim = DB::table('simulador_pix_devolucoes')->where('chave_idempotencia', $d->chave_idempotencia)->get();
        $this->assertCount(1, $sim, 'mesma chave no provedor');
        $this->assertSame(2, (int) $sim[0]->tentativas);
        $this->assertDatabaseHas('audit_events', ['acao' => 'devolucao_retentada', 'ator_id' => $this->gerente->id]);

        // Concluída não pode ser "tentada de novo".
        $this->entrarComo($this->gerente)->post(route('devolucoes.tentar', [$this->ordem, $d]))->assertSessionHasErrors('devolucao');
    }

    public function test_mesma_chave_nao_duplica_devolucao_no_provedor(): void
    {
        $p = $this->pixConfirmado();
        $sim = app(SimuladorPix::class);
        $a = $sim->devolver($p, 5000, 'chave-x');
        $b = $sim->devolver($p, 5000, 'chave-x');
        $this->assertSame('concluida', $a['status']);
        $this->assertSame($a['id_provedor'], $b['id_provedor']);
        $this->assertSame(1, DB::table('simulador_pix_devolucoes')->count());
        $this->assertSame(5000, (int) DB::table('simulador_pix_devolucoes')->sum('valor_centavos'));

        // Reenviar ao provedor uma devolução já concluída não altera nada.
        $d = app(Financeiro::class)->devolver($p, $this->gerente, 1000, 'Ajuste combinado');
        app(Financeiro::class)->solicitarDevolucaoAoProvedor($d);
        $this->assertSame(1, DB::table('simulador_pix_devolucoes')->where('chave_idempotencia', $d->chave_idempotencia)->count());
        $this->assertSame(1, Devolucao::where('status', 'concluida')->count());

        // O provedor recusa o que excede o pago menos o já devolvido (por qualquer chave).
        $r = $sim->devolver($p, 48000, 'chave-y');
        $this->assertSame('recusada', $r['status']);
    }

    public function test_limite_considera_processando_mas_nao_falhou(): void
    {
        $p = $this->pixConfirmado();
        // Provedor assíncrono: aceita a solicitação mas ainda não concluiu.
        $this->app->instance(ProvedorPix::class, new class extends SimuladorPix
        {
            public function devolver(Pagamento $pagamento, int $valorCentavos, string $chaveIdempotencia): array
            {
                return ['status' => 'processando', 'id_provedor' => 'D-ASYNC-'.$chaveIdempotencia, 'mensagem' => null];
            }
        });
        $this->app->forgetInstance(Financeiro::class);
        $f = app(Financeiro::class);

        $d = $f->devolver($p, $this->gerente, 48000, 'Serviço não realizado');
        $this->assertSame('processando', $d->status);
        $this->assertSame(0, $f->resumo($this->ordem)['saldo'], 'processando não abate');
        try {
            $f->devolver($p, $this->gerente, 1, 'Segunda devolução');
            $this->fail('processando deve reservar o limite');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('R$ 0,00', $e->errors()['valor'][0]);
        }

        // Ao falhar, o valor deixa de ser reservado.
        $d->update(['status' => 'falhou', 'mensagem' => 'recusada']);
        $this->app->instance(ProvedorPix::class, new SimuladorPix);
        $this->app->forgetInstance(Financeiro::class);
        $nova = app(Financeiro::class)->devolver($p, $this->gerente, 48000, 'Nova devolução');
        $this->assertSame('concluida', $nova->status);
        // E a falha antiga não pode mais ser retentada (devolveria em dobro).
        $this->expectException(ValidationException::class);
        app(Financeiro::class)->tentarDevolucaoNovamente($d->fresh(), $this->gerente);
    }

    public function test_provedor_indisponivel_deixa_devolucao_falhou_com_mensagem(): void
    {
        $p = $this->pixConfirmado();
        $this->app->instance(ProvedorPix::class, new ProvedorIndisponivel);
        $this->app->forgetInstance(Financeiro::class);
        $d = app(Financeiro::class)->devolver($p, $this->gerente, 5000, 'Peça não utilizada');
        $this->assertSame('falhou', $d->status);
        $this->assertStringContainsString('Integração Pix real não configurada', $d->mensagem);
        $this->assertSame(0, app(Financeiro::class)->resumo($this->ordem)['saldo']);
        $this->assertSame(0, DB::table('simulador_pix_devolucoes')->count(), 'nada enviado ao simulador nem a banco real');
    }

    public function test_permissao_e_unidade_verificadas_no_servidor(): void
    {
        $p = $this->pixConfirmado();
        config(['oficina.pix.simular_falha_devolucao' => true]);
        $d = app(Financeiro::class)->devolver($p, $this->gerente, 5000, 'Peça não utilizada');
        config(['oficina.pix.simular_falha_devolucao' => false]);

        $this->entrarComo($this->recepcao)->post("/ordens/{$this->ordem->numero}/pagamentos/{$p->id}/devolver", ['valor_devolucao' => '10,00', 'motivo' => 'Sem permissão'])->assertForbidden();
        $this->entrarComo($this->recepcao)->post(route('devolucoes.tentar', [$this->ordem, $d]))->assertForbidden();
        $this->entrarComo($this->recepcao)->get("/ordens/{$this->ordem->numero}?aba=pagamentos")->assertOk()->assertDontSee('Tentar de novo');
        $outraUnidade = $this->usuario(Papel::Gerente, Unidade::factory()->create());
        $this->entrarComo($outraUnidade)->post(route('devolucoes.tentar', [$this->ordem, $d]))->assertForbidden();
        $this->assertSame('falhou', $d->fresh()->status);

        // Devolução de outra OS na URL desta OS: 404.
        $outra = $this->ordemAprovada(1000, 'XYZ9K88');
        $this->entrarComo($this->gerente)->post(route('devolucoes.tentar', [$outra, $d]))->assertNotFound();

        // Resolução manual retira da lista e bloqueia nova tentativa.
        $this->entrarComo($this->gerente)->post(route('pendencias.resolver', ['devolucao_falhou', $d->id]), ['resolucao' => 'Devolvido em dinheiro no balcão'])->assertSessionHasNoErrors();
        $this->assertNotContains('devolucao_falhou', $this->tiposPendentes());
        $this->entrarComo($this->gerente)->post(route('devolucoes.tentar', [$this->ordem, $d]))->assertSessionHasErrors('devolucao');
        $this->entrarComo($this->gerente)->get("/ordens/{$this->ordem->numero}?aba=pagamentos")->assertOk()->assertDontSee('Tentar de novo');
        $this->assertSame(0, DB::table('simulador_pix_devolucoes')->where('status', 'devolvida')->count());
    }

    public function test_dinheiro_continua_manual_concluida_e_descontando_gaveta(): void
    {
        $f = app(Financeiro::class);
        $p = $f->registrarManual($this->ordem, $this->recepcao, 'dinheiro', 48000);
        $d = $f->devolver($p, $this->gerente, 5000, 'Devolvido em dinheiro ao cliente');
        $this->assertSame('concluida', $d->status);
        $this->assertNotNull($d->concluida_em);
        $hoje = Fuso::exibir(now(), 'Y-m-d');
        $this->assertSame(43000, $f->dinheiroEsperado($this->unidade->id, $hoje));
        $this->assertSame(0, DB::table('simulador_pix_devolucoes')->count(), 'dinheiro não passa pelo provedor');
        $this->assertDatabaseHas('audit_events', ['acao' => 'devolucao_registrada']);

        // Devolução Pix (outra OS) não mexe na gaveta.
        $this->ordem = $this->ordemAprovada(10000, 'PIX1A23');
        $pix = $this->pixConfirmado();
        $f->devolver($pix, $this->gerente, 2000, 'Ajuste');
        $this->assertSame(43000, $f->dinheiroEsperado($this->unidade->id, $hoje));
    }

    public function test_devolucao_pix_de_pagamento_em_revisao_nao_abate_saldo(): void
    {
        $f = app(Financeiro::class);
        $p = $this->pixConfirmado(50000); // pago a mais: em revisão
        $this->assertSame('em_revisao', $p->status);
        $d = $f->devolver($p, $this->gerente, 2000, 'Devolução do excedente');
        $this->assertSame('concluida', $d->status);
        $this->assertSame(48000, $f->resumo($this->ordem)['saldo'], 'regra preservada: pagamento em revisão não conta nem abate');
    }
}
