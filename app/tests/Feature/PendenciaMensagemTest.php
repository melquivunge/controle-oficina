<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\MessageDelivery;
use App\Models\Ordem;
use App\Models\ResolucaoPendencia;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Mensagens não enviadas nas Pendências (11/11/2026): some ao resolver, some quando enviada depois, e resolver todas de uma vez. */
class PendenciaMensagemTest extends TestCase
{
    private Unidade $u;
    private User $gerente;
    private Ordem $o;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado']);
        Storage::fake('local');
        $this->u = Unidade::factory()->create();
        $this->gerente = $this->usuario(Papel::Gerente, $this->u);
        $this->o = Ordem::factory()->create(['unidade_id' => $this->u->id]);
    }

    private function msg(string $status, string $tipo = 'documento_os'): MessageDelivery
    {
        return MessageDelivery::create(['ordem_id' => $this->o->id, 'unidade_id' => $this->u->id, 'tipo' => $tipo, 'canal' => 'whatsapp',
            'destino' => '61991234567', 'conteudo' => 'x', 'status' => $status, 'tentativas' => 6, 'ultimo_erro' => 'Canal desativado']);
    }

    public function test_resolver_tira_da_lista_e_enviada_depois_some_sozinha(): void
    {
        $a = $this->msg('falhou');
        $b = $this->msg('falhou', 'orcamento');
        $this->entrarComo($this->gerente)->get(route('pendencias.index'))->assertSee('2 em aberto');

        // Mesmo vindo de "mostrar resolvidas", volta para a lista sem as resolvidas.
        $this->entrarComo($this->gerente)->from(route('pendencias.index', ['resolvidas' => 1]))
            ->post(route('pendencias.resolver', ['mensagem_falhou', $a->id]), ['resolucao' => 'Reenviado, cliente recebeu'])
            ->assertRedirect(route('pendencias.index'));
        $this->entrarComo($this->gerente)->get(route('pendencias.index'))->assertSee('1 em aberto');

        // Um envio posterior da mesma mensagem (OS + tipo) concluído tira a falha antiga da lista.
        $this->msg('enviado', 'orcamento');
        $this->entrarComo($this->gerente)->get(route('pendencias.index'))->assertSee('0 em aberto');
        $this->assertSame('falhou', $b->fresh()->status, 'o registro da falha continua guardado');
    }

    public function test_resolver_todas_as_mensagens_de_uma_vez_com_auditoria_e_permissao(): void
    {
        $ids = collect(range(1, 3))->map(fn () => $this->msg('falhou')->id);
        $this->entrarComo($this->gerente)->get(route('pendencias.index'))->assertSee('Resolver todas as mensagens não enviadas (3)');

        $this->entrarComo($this->usuario(Papel::Recepcao, $this->u))->post(route('pendencias.mensagens.resolver'), ['resolucao' => 'Limpando tudo'])->assertForbidden();
        $this->entrarComo($this->gerente)->post(route('pendencias.mensagens.resolver'), ['resolucao' => 'x'])->assertSessionHasErrors('resolucao');

        $this->entrarComo($this->gerente)->post(route('pendencias.mensagens.resolver'), ['resolucao' => 'Número estava desativado; reenviado'])
            ->assertRedirect(route('pendencias.index'))->assertSessionHas('sucesso', '3 mensagem(ns) não enviada(s) marcada(s) como resolvida(s).');
        $this->entrarComo($this->gerente)->get(route('pendencias.index'))->assertSee('0 em aberto')->assertDontSee('Resolver todas');
        $this->assertSame(3, ResolucaoPendencia::whereIn('referencia_id', $ids)->where('tipo', 'mensagem_falhou')->count());
        $this->assertDatabaseHas('audit_events', ['acao' => 'pendencia_resolvida', 'motivo' => 'Número estava desativado; reenviado']);
    }
}
