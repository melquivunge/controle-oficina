<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\AuditEvent;
use App\Models\Divergencia;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Models\WhatsappConversa;
use App\Models\WhatsappNumero;
use App\Services\Conferencias;
use App\Services\Entradas;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Supervisor multiunidade (decisões de 30/10/2026): visão de gerente em A e B; C fica fora (403 no servidor). */
class SupervisorMultiunidadeTest extends TestCase
{
    private Unidade $a;
    private Unidade $b;
    private Unidade $c;
    private User $dono;
    private User $sup;
    /** @var array<string, Ordem> */
    private array $ordens = [];
    /** @var array<string, User> */
    private array $rec = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['oficina.integracoes' => 'simulado']);
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->a = Unidade::factory()->create(['nome' => 'Unidade Alfa']);
        $this->b = Unidade::factory()->create(['nome' => 'Unidade Beta']);
        $this->c = Unidade::factory()->create(['nome' => 'Unidade Gama']);
        $this->dono = $this->usuario(Papel::Proprietario);
        $this->sup = User::factory()->papel(Papel::Supervisor, $this->a)->create(['name' => 'Sofia Supervisora']);
        $this->sup->unidadesSupervisionadas()->sync([$this->a->id, $this->b->id]);
        $gerC = $this->usuario(Papel::Gerente, $this->c);

        foreach (['a' => $this->a, 'b' => $this->b, 'c' => $this->c] as $k => $u) {
            $this->rec[$k] = $this->usuario(Papel::Recepcao, $u);
            $mec = $this->usuario(Papel::Mecanico, $u);
            $o = app(Entradas::class)->registrar($this->rec[$k], ['unidade_id' => $u->id, 'nome' => 'Cliente '.strtoupper($k), 'telefone' => '6199123456'.ord($k) % 10,
                'placa' => strtoupper($k).strtoupper($k).strtoupper($k).'1A23', 'motivo' => 'Revisão']);
            app(Ordens::class)->iniciarDiagnostico($o, $mec);
            $orcador = $k === 'c' ? $gerC : $this->sup;
            $v = app(Orcamentos::class)->salvarRascunho($o, $orcador, [['descricao' => 'Reforma de roda '.strtoupper($k), 'quantidade' => '1', 'preco' => '100']]);
            app(Orcamentos::class)->enviar($v, $orcador);
            $this->ordens[$k] = $o->fresh();
        }
    }

    public function test_papel_supervisor_tem_permissoes_do_gerente_sem_visao_global(): void
    {
        $this->assertSame('Supervisor', Papel::Supervisor->rotulo());
        foreach (Papel::Gerente->permissoes() as $p) {
            $this->assertTrue($this->sup->pode($p), "supervisor deveria ter {$p}");
        }
        $this->assertFalse($this->sup->visaoGlobal());
        $this->assertFalse($this->sup->pode('configurar'));
        $this->assertTrue($this->sup->acessaUnidade($this->a->id));
        $this->assertTrue($this->sup->acessaUnidade($this->b->id));
        $this->assertFalse($this->sup->acessaUnidade($this->c->id));
        $this->assertSame([$this->a->id, $this->b->id], $this->sup->unidadeIds());

        // Unidade desativada sai do conjunto; todas desativadas revogam o acesso.
        $this->b->update(['ativa' => false]);
        $s = $this->sup->fresh();
        $this->assertSame([$this->a->id], $s->unidadeIds());
        $this->a->update(['ativa' => false]);
        $s = $this->sup->fresh();
        $this->assertTrue($s->unidadeInativa());
        $this->assertFalse($s->pode('orcar'));
    }

    public function test_painel_lista_unidades_do_supervisor_e_bloqueia_outra(): void
    {
        $r = $this->entrarComo($this->sup)->get('/?periodo=7')->assertOk()
            ->assertSee('name="unidade"', false)->assertSee('Unidade Alfa')->assertSee('Unidade Beta')->assertDontSee('Unidade Gama');
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], $r->viewData('unidades')->pluck('id')->all());
        $this->assertSame(2, $r->viewData('total')['entradas']);
        $this->entrarComo($this->sup)->get("/?unidade={$this->a->id}")->assertOk();
        $this->assertSame(1, $this->entrarComo($this->sup)->get("/?periodo=7&unidade={$this->b->id}")->viewData('total')['entradas']);
        $this->entrarComo($this->sup)->get("/?unidade={$this->c->id}")->assertForbidden();
        $this->entrarComo($this->sup)->get("/painel/exportar?unidade={$this->c->id}")->assertForbidden();
        $csv = $this->entrarComo($this->sup)->get('/painel/exportar?periodo=7')->assertOk()->streamedContent();
        $this->assertStringContainsString('Unidade Alfa', $csv);
        $this->assertStringContainsString('Unidade Beta', $csv);
        $this->assertStringNotContainsString('Unidade Gama', $csv);
        // Gerente de unidade única continua sem seletor.
        $this->entrarComo($this->usuario(Papel::Gerente, $this->a))->get('/')->assertOk()->assertDontSee('name="unidade"', false);
    }

    public function test_ordens_de_a_e_b_acessiveis_e_de_c_403(): void
    {
        $this->entrarComo($this->sup)->get("/ordens/{$this->ordens['a']->numero}")->assertOk();
        $this->entrarComo($this->sup)->get("/ordens/{$this->ordens['b']->numero}")->assertOk();
        $this->entrarComo($this->sup)->get("/ordens/{$this->ordens['c']->numero}")->assertForbidden();
        $lista = $this->entrarComo($this->sup)->get('/ordens')->assertOk();
        $lista->assertSee($this->ordens['a']->numero)->assertSee($this->ordens['b']->numero)->assertDontSee($this->ordens['c']->numero);
        $this->entrarComo($this->sup)->get("/ordens?unidade={$this->c->id}")->assertForbidden();
        $this->assertSame(2, Ordem::visiveisPara($this->sup)->count());
        // Ação no servidor: definir prazo na OS de C é negado mesmo por requisição direta.
        $this->entrarComo($this->sup)->post("/ordens/{$this->ordens['c']->numero}/prazo-entrega", ['prazo_dias' => 3])->assertForbidden();
        $this->entrarComo($this->sup)->post("/ordens/{$this->ordens['b']->numero}/prazo-entrega", ['prazo_dias' => 3])->assertRedirect();
        $this->assertNotNull($this->ordens['b']->fresh()->entrega_prevista_em);
    }

    public function test_caixa_e_conferencia_por_unidade(): void
    {
        $this->entrarComo($this->sup)->get("/caixa?unidade={$this->a->id}")->assertOk()->assertSee('Unidade Beta');
        $this->entrarComo($this->sup)->get("/caixa?unidade={$this->b->id}")->assertOk();
        $this->entrarComo($this->sup)->get("/caixa?unidade={$this->c->id}")->assertForbidden();
        $this->entrarComo($this->sup)->get("/conferencia?unidade={$this->b->id}")->assertOk();
        $this->entrarComo($this->sup)->get("/conferencia?unidade={$this->c->id}")->assertForbidden();
        $this->entrarComo($this->sup)->post('/conferencia', ['unidade' => $this->c->id, 'presentes' => [], 'placas' => ''])->assertForbidden();

        // Unidade B não tem gerente: o supervisor é o revisor das divergências e pode resolvê-las.
        app(Conferencias::class)->registrar($this->b->id, $this->rec['b'], [], []);
        $div = Divergencia::where('unidade_id', $this->b->id)->firstOrFail();
        $this->assertSame($this->sup->id, $div->revisor_id);
        $this->entrarComo($this->sup)->post("/conferencia/divergencias/{$div->id}", ['resolucao' => 'Veículo no lava-jato, confirmado'])->assertRedirect();
        $this->assertSame($this->sup->id, $div->fresh()->resolvido_por);
        app(Conferencias::class)->registrar($this->c->id, $this->rec['c'], [], []);
        $divC = Divergencia::where('unidade_id', $this->c->id)->firstOrFail();
        $this->entrarComo($this->sup)->post("/conferencia/divergencias/{$divC->id}", ['resolucao' => 'Tentativa fora da unidade'])->assertForbidden();
    }

    public function test_pendencias_so_das_unidades_do_supervisor(): void
    {
        foreach (['a', 'b', 'c'] as $k) {
            app(Conferencias::class)->registrar($this->ordens[$k]->unidade_id, $this->rec[$k], [], []);
        }
        $itens = collect($this->entrarComo($this->sup)->get('/pendencias')->assertOk()->viewData('itens')->items());
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], $itens->pluck('unidade_id')->unique()->values()->all());
        $this->entrarComo($this->sup)->get('/pendencias?aba=mensagens')->assertOk();
    }

    public function test_whatsapp_conversas_de_a_e_b_e_403_em_c(): void
    {
        $conv = [];
        foreach (['a' => $this->a, 'b' => $this->b, 'c' => $this->c] as $k => $u) {
            $n = WhatsappNumero::create(['nome' => 'WA '.$k, 'telefone' => '613000010'.ord($k) % 10, 'unidade_id' => $u->id, 'provedor' => 'simulado', 'minutos_alerta' => 15,
                'criado_por' => $this->dono->id, 'modelos' => []]);
            $conv[$k] = WhatsappConversa::create(['numero_id' => $n->id, 'unidade_id' => $u->id, 'contato_telefone' => '6199888770'.ord($k) % 10, 'contato_wa_id' => '556199888770'.ord($k) % 10, 'situacao' => 'nova']);
        }
        $this->entrarComo($this->sup)->get("/whatsapp/conversas/{$conv['a']->id}")->assertOk();
        $this->entrarComo($this->sup)->get("/whatsapp/conversas/{$conv['b']->id}")->assertOk();
        $this->entrarComo($this->sup)->get("/whatsapp/conversas/{$conv['c']->id}")->assertForbidden();
        $this->entrarComo($this->sup)->post("/whatsapp/conversas/{$conv['c']->id}/responder", ['texto' => 'oi'])->assertForbidden();
        $this->assertEqualsCanonicalizing([$conv['a']->id, $conv['b']->id], WhatsappConversa::visiveisPara($this->sup)->pluck('id')->all());
    }

    public function test_orcamentos_e_auditoria_filtrados(): void
    {
        $r = $this->entrarComo($this->sup)->get('/orcamentos')->assertOk();
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], collect($r->viewData('orcamentos')->items())->pluck('unidade_id')->all());
        $this->entrarComo($this->sup)->get("/orcamentos?unidade={$this->b->id}")->assertOk();
        $this->entrarComo($this->sup)->get("/orcamentos?unidade={$this->c->id}")->assertForbidden();

        $eventos = collect($this->entrarComo($this->sup)->get('/auditoria')->assertOk()->viewData('eventos')->items());
        $this->assertNotEmpty($eventos);
        $this->assertEmpty($eventos->where('unidade_id', $this->c->id));
        $this->assertNotEmpty($eventos->where('unidade_id', $this->b->id));
        $this->entrarComo($this->sup)->get("/auditoria?unidade={$this->c->id}")->assertForbidden();
        $this->assertSame(0, AuditEvent::visiveisPara($this->sup)->where('unidade_id', $this->c->id)->count());
    }

    public function test_proprietario_configura_unidades_do_supervisor_com_auditoria(): void
    {
        $this->entrarComo($this->dono)->get('/configuracoes/usuarios/novo')->assertOk()->assertSee('Unidades supervisionadas')->assertSee('<option value="supervisor"', false);
        // Supervisor sem unidades é recusado.
        $this->entrarComo($this->dono)->post('/configuracoes/usuarios', ['name' => 'Gustavo Goiânia', 'email' => 'gus@ex.test', 'papel' => 'supervisor',
            'password' => 'senhaSegura123', 'password_confirmation' => 'senhaSegura123'])->assertSessionHasErrors('unidades');

        $this->entrarComo($this->dono)->post('/configuracoes/usuarios', ['name' => 'Gustavo Goiânia', 'email' => 'gus@ex.test', 'papel' => 'supervisor',
            'unidades' => [$this->b->id, $this->c->id], 'password' => 'senhaSegura123', 'password_confirmation' => 'senhaSegura123'])->assertSessionHasNoErrors();
        $g = User::where('email', 'gus@ex.test')->sole();
        $this->assertSame(Papel::Supervisor, $g->papel);
        $this->assertSame($this->b->id, $g->unidade_id, 'principal = menor id quando não informada');
        $this->assertSame([$this->b->id, $this->c->id], $g->unidadeIds());
        $this->assertDatabaseHas('audit_events', ['acao' => 'usuario_criado', 'entidade_id' => $g->id]);
        $this->assertSame([$this->b->id, $this->c->id], AuditEvent::where('acao', 'usuario_criado')->where('entidade_id', $g->id)->sole()->depois['unidades_supervisionadas']);

        // Troca para A (principal) + C: a principal entra no conjunto; B sai e passa a dar 403.
        $this->entrarComo($this->dono)->put("/configuracoes/usuarios/{$g->id}", ['name' => 'Gustavo Goiânia', 'email' => 'gus@ex.test', 'papel' => 'supervisor',
            'unidade_id' => $this->a->id, 'unidades' => [$this->c->id]])->assertSessionHasNoErrors();
        $g = $g->fresh();
        $this->assertSame([$this->a->id, $this->c->id], $g->unidadeIds());
        $ev = AuditEvent::where('acao', 'usuario_alterado')->where('entidade_id', $g->id)->sole();
        $this->assertSame([$this->b->id, $this->c->id], $ev->antes['unidades_supervisionadas']);
        $this->assertSame([$this->a->id, $this->c->id], $ev->depois['unidades_supervisionadas']);
        // Sessão nova: AuthenticateSession guardou o hash de senha do proprietário (senha de $g é outra).
        $this->flushSession();
        $this->entrarComo($g)->get("/ordens/{$this->ordens['b']->numero}")->assertForbidden();
        $this->entrarComo($g)->get("/ordens/{$this->ordens['c']->numero}")->assertOk();

        // Voltar a gerente remove o vínculo multiunidade.
        $this->flushSession();
        $this->entrarComo($this->dono)->put("/configuracoes/usuarios/{$g->id}", ['name' => 'Gustavo Goiânia', 'email' => 'gus@ex.test', 'papel' => 'gerente',
            'unidade_id' => $this->a->id, 'unidades' => [$this->c->id]])->assertSessionHasNoErrors();
        $g = $g->fresh();
        $this->assertSame(0, $g->unidadesSupervisionadas()->count());
        $this->assertFalse($g->acessaUnidade($this->c->id));

        // Supervisor não acessa a configuração de usuários.
        $this->flushSession();
        $this->entrarComo($this->sup)->get('/configuracoes/usuarios')->assertForbidden();
    }
}
