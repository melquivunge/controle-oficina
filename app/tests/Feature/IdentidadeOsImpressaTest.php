<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Livewire\NovaEntrada;
use App\Models\AuditEvent;
use App\Models\Empresa;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Rules\Cnpj;
use App\Services\Conferencias;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Identidade da empresa, endereço do cliente, vendedor na OS, entrada sem veículo e OS impressa (31/10/2026). */
class IdentidadeOsImpressaTest extends TestCase
{
    private Unidade $unidade;
    private User $dono;
    private User $gerente;
    private User $recepcao;
    private User $mecanico;

    private const ENDERECO = ['cep' => '70.040-010', 'logradouro' => 'Rua das Rodas', 'numero' => '17', 'complemento' => 'Loja 2', 'bairro' => 'Asa Sul', 'cidade' => 'Brasília', 'uf' => 'df'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->unidade = Unidade::factory()->create();
        $this->dono = $this->usuario(Papel::Proprietario);
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
    }

    private function empresaValida(array $extra = []): array
    {
        $base = ['razao_social' => 'Rodas Brilho Ltda', 'nome_fantasia' => 'Rodas Brilho', 'cnpj' => '11.222.333/0001-81', 'telefone' => '(61) 3333-4444',
            'email' => 'contato@rodas.test', 'cor_principal' => '#1F4E79'] + self::ENDERECO;
        return array_merge($base, $extra);
    }

    /** Ordem com orçamento enviado pelo gerente via HTTP (define o vendedor padrão) e aprovado presencialmente. */
    private function ordemAprovada(array $entrada = []): Ordem
    {
        $o = app(Entradas::class)->registrar($this->recepcao, $entrada + ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana Costa', 'telefone' => '61991234567',
            'placa' => 'RDA1B23', 'modelo' => 'Onix', 'motivo' => 'Jogo de rodas — reforma geral aro 17', 'observacoes' => 'NOTA-INTERNA-RECEPCAO']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->mecanico);
        app(Ordens::class)->registrarDiagnostico($o, 'Rodas com empeno leve.', $this->mecanico);
        $v = app(Orcamentos::class)->salvarRascunho($o, $this->gerente, [['descricao' => 'Reforma geral de roda aro 17', 'quantidade' => '4', 'preco' => '250,00']]);
        $this->entrarComo($this->gerente)->post(route('orcamentos.enviar', [$o, $v]))->assertSessionHasNoErrors();
        app(Orcamentos::class)->decidirPresencial($v->fresh(), $this->recepcao, 'aprovado', 'Cliente aprovou no balcão');
        app(Financeiro::class)->registrarManual($o->fresh(), $this->recepcao, 'dinheiro', 40000, 'Sinal');

        return $o->fresh();
    }

    public function test_cnpj_numerico_e_alfanumerico_com_digitos_verificadores(): void
    {
        $this->assertTrue(Cnpj::valido('11.222.333/0001-81'));
        $this->assertFalse(Cnpj::valido('11.222.333/0001-82'));
        $this->assertFalse(Cnpj::valido('11111111111111'));
        $this->assertFalse(Cnpj::valido('123'));
        $this->assertTrue(Cnpj::valido('12.ABC.345/01DE-35')); // exemplo oficial da Receita (CNPJ alfanumérico)
        $this->assertFalse(Cnpj::valido('12.ABC.345/01DE-36'));
    }

    public function test_somente_proprietario_com_senha_confirmada_edita_identidade_e_auditoria_registra(): void
    {
        // Sem reautenticação recente: pede a senha (antes de qualquer sessão com senha confirmada).
        $this->actingAs($this->dono)->withSession(['dois_fatores_ok' => $this->dono->id])->get(route('empresa.edit'))->assertRedirect(route('password.confirm'));
        foreach ([$this->gerente, $this->recepcao, $this->mecanico] as $u) {
            $this->entrarComo($u)->get(route('empresa.edit'))->assertForbidden();
            $this->entrarComo($u)->put(route('empresa.update'), $this->empresaValida())->assertForbidden();
        }

        $this->entrarComo($this->dono)->get(route('empresa.edit'))->assertOk()->assertSee('Identidade da empresa');
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresaValida(['cnpj' => '11.222.333/0001-82']))->assertSessionHasErrors('cnpj');
        $this->assertSame(0, Empresa::count());

        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresaValida())->assertSessionHasNoErrors()->assertRedirect(route('empresa.edit'));
        $e = Empresa::atual();
        $this->assertSame(['11222333000181', '6133334444', '#1f4e79', '70040010', 'DF'], [$e->cnpj, $e->telefone, $e->cor_principal, $e->cep, $e->uf]);
        $ev = AuditEvent::where('acao', 'empresa_alterada')->sole();
        $this->assertSame('11222333000181', $ev->depois['cnpj']);
        $this->assertSame($this->dono->id, $ev->ator_id);
    }

    public function test_logo_em_disco_privado_servida_com_nosniff_e_svg_recusado(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresaValida(['logo' => $svg]))->assertSessionHasErrors('logo');

        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresaValida(['logo' => $this->foto('logo.png')]))->assertSessionHasNoErrors();
        $caminho = Empresa::atual()->logo_caminho;
        $this->assertStringStartsWith('empresa/', $caminho);
        Storage::disk('local')->assertExists($caminho);
        Storage::disk('public')->assertMissing($caminho);

        // Logo é pública desde 31/10/2026 (aparece na tela de entrada); continua fora de /storage público e com nosniff.
        auth()->logout();
        $this->flushSession();
        $r = $this->get(route('empresa.logo'))->assertOk();
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", $r->headers->get('Content-Security-Policy'));
    }

    public function test_unidade_com_cnpj_proprio_aparece_na_os_impressa_e_cnpj_invalido_recusado(): void
    {
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresaValida())->assertSessionHasNoErrors();
        $this->entrarComo($this->dono)->put(route('empresa.unidade.update', $this->unidade), ['cnpj' => '11222333000180'])->assertSessionHasErrors('cnpj', null, 'unidade'.$this->unidade->id);
        $this->entrarComo($this->dono)->put(route('empresa.unidade.update', $this->unidade), ['cnpj' => '12.ABC.345/01DE-35', 'telefone' => '61 98888-7777', 'logradouro' => 'Av. Filial', 'numero' => '9', 'cidade' => 'Taguatinga', 'uf' => 'DF'])
            ->assertSessionHasNoErrors();
        $this->assertSame('12ABC34501DE35', $this->unidade->fresh()->cnpj);
        $this->assertTrue(AuditEvent::where('acao', 'unidade_identidade_alterada')->where('entidade_id', $this->unidade->id)->exists());

        $o = $this->ordemAprovada();
        $this->entrarComo($this->recepcao)->get(route('ordens.impressao', $o))->assertOk()
            ->assertSee('12.ABC.345/01DE-35')->assertSee('(61) 98888-7777')->assertSee('Av. Filial')->assertDontSee('11.222.333/0001-81');
    }

    public function test_endereco_do_cliente_editavel_por_recepcao_e_invisivel_ao_mecanico(): void
    {
        $o = $this->ordemAprovada();
        $this->entrarComo($this->recepcao)->put(route('clientes.update', $o->cliente), ['nome' => 'Mariana Costa', 'telefone' => '61991234567'] + self::ENDERECO)->assertSessionHasNoErrors();
        $c = $o->cliente->fresh();
        $this->assertSame(['70040010', 'Rua das Rodas', 'DF'], [$c->cep, $c->logradouro, $c->uf]);
        $this->assertSame('Rua das Rodas', AuditEvent::where('acao', 'cliente_alterado')->latest('id')->first()->depois['logradouro']);
        $this->entrarComo($this->recepcao)->put(route('clientes.update', $o->cliente), ['nome' => 'Mariana Costa', 'telefone' => '61991234567', 'cep' => '123', 'uf' => 'XX'])->assertSessionHasErrors(['cep', 'uf']);

        $this->entrarComo($this->recepcao)->get(route('clientes.show', $c))->assertOk()->assertSee('Rua das Rodas');
        $this->entrarComo($this->recepcao)->get(route('ordens.show', $o))->assertOk()->assertSee('Rua das Rodas');
        foreach (['resumo', 'diagnostico', 'orcamento', 'historico'] as $aba) {
            $this->entrarComo($this->mecanico)->get(route('ordens.show', $o).'?aba='.$aba)->assertOk()->assertDontSee('Rua das Rodas')->assertDontSee('70040');
        }
        $this->entrarComo($this->mecanico)->put(route('clientes.update', $c), ['nome' => 'X', 'telefone' => '61991234567', 'logradouro' => 'Hack'])->assertForbidden();
        $this->assertSame('Rua das Rodas', $c->fresh()->logradouro);
    }

    public function test_endereco_na_nova_entrada_e_recusado_ao_mecanico_no_servico(): void
    {
        Livewire::actingAs($this->recepcao)->test(NovaEntrada::class)
            ->set('telefone', '61991112222')->set('placa', 'ABC1D23')->set('nome', 'Paulo Lima')->set('motivo', 'Rodas riscadas')
            ->set('endereco.cep', '70040-010')->set('endereco.logradouro', 'Quadra 1')->set('endereco.uf', 'DF')
            ->call('salvar')->assertHasNoErrors();
        $this->assertSame(['70040010', 'Quadra 1'], [Ordem::sole()->cliente->cep, Ordem::sole()->cliente->logradouro]);
        // Sem ver_contato_cliente o endereço enviado ao serviço é ignorado.
        $semContato = $this->usuario(Papel::Mecanico, $this->unidade);
        $semContato->forceFill(['permissoes_extras' => ['ver_contato_cliente']])->save();
        $this->assertFalse($semContato->pode('ver_contato_cliente'));
    }

    public function test_entrada_sem_veiculo_exige_item_e_banco_garante(): void
    {
        Livewire::actingAs($this->recepcao)->test(NovaEntrada::class)
            ->set('telefone', '61991112222')->set('nome', 'Paulo Lima')->set('motivo', 'Reforma')->set('sem_veiculo', true)
            ->call('salvar')->assertHasErrors(['item_descricao' => 'required'])->assertHasNoErrors('placa');
        Livewire::actingAs($this->recepcao)->test(NovaEntrada::class)
            ->set('telefone', '61991112222')->set('nome', 'Paulo Lima')->set('motivo', 'Reforma geral')->set('sem_veiculo', true)
            ->set('item_descricao', 'Jogo de rodas aro 17 — 4 unidades')->call('salvar')->assertHasNoErrors();
        $o = Ordem::sole();
        $this->assertNull($o->veiculo_id);
        $this->assertSame('Jogo de rodas aro 17 — 4 unidades', $o->item_descricao);
        $this->assertStringContainsString('do item “Jogo de rodas aro 17', DB::table('message_deliveries')->where('ordem_id', $o->id)->value('conteudo'));

        $this->expectException(QueryException::class);
        DB::table('ordens')->where('id', $o->id)->update(['item_descricao' => null]);
    }

    public function test_os_sem_veiculo_em_todas_as_listas_e_telas_sem_erro(): void
    {
        $o = $this->ordemAprovada(['sem_veiculo' => true, 'item_descricao' => 'Jogo de rodas aro 17 — 4 unidades', 'placa' => null]);
        $this->assertNull($o->veiculo_id);
        app(Ordens::class)->atribuirMecanico($o, $this->mecanico, $this->gerente);
        app(Conferencias::class)->registrar($this->unidade->id, $this->recepcao, [], []); // OS sem veículo ausente: divergência pelo item
        $this->assertDatabaseHas('divergencias', ['ordem_id' => $o->id, 'placa' => null]);

        $item = 'Jogo de rodas aro 17';
        foreach ([$this->dono, $this->gerente, $this->recepcao] as $u) {
            foreach (['/', '/ordens', '/ordens?busca=rodas+aro', '/conferencia', '/orcamentos?status=todos', '/caixa', '/clientes/'.$o->cliente_id, '/pendencias', route('ordens.impressao', $o)] as $url) {
                if ($url === '/pendencias' && ! $u->pode('resolver_divergencias') || $url === '/caixa' && ! $u->pode('receber_manual')) {
                    continue;
                }
                $this->entrarComo($u)->get($url)->assertOk();
            }
            foreach (['resumo', 'diagnostico', 'orcamento', 'pagamentos', 'documentos', 'historico'] as $aba) {
                $this->entrarComo($u)->get(route('ordens.show', $o).'?aba='.$aba)->assertOk()->assertSee($item);
            }
        }
        $this->entrarComo($this->gerente)->get('/ordens?busca=rodas+aro')->assertSee($o->numero);
        $this->entrarComo($this->gerente)->get('/conferencia')->assertSee($item);
        $this->entrarComo($this->mecanico)->get('/fila')->assertOk()->assertSee($item);
        $this->entrarComo($this->mecanico)->get(route('ordens.show', $o))->assertOk()->assertSee($item);
        $token = app(Orcamentos::class)->novoLink($o, $this->gerente);
        $this->get(route('portal.show', $token))->assertOk()->assertSee($item);
        $this->get(route('portal.impressao', $token))->assertOk()->assertSee($item);
    }

    public function test_vendedor_padrao_e_quem_enviou_alteravel_so_por_quem_orca_e_auditado(): void
    {
        $o = $this->ordemAprovada();
        $this->assertSame($this->gerente->id, $o->vendedor_id);
        $this->assertSame($this->gerente->id, AuditEvent::where('acao', 'vendedor_definido')->where('entidade_id', $o->id)->sole()->ator_id);

        $this->entrarComo($this->recepcao)->post(route('ordens.vendedor', $o), ['vendedor_id' => $this->dono->id])->assertForbidden();
        $this->entrarComo($this->mecanico)->post(route('ordens.vendedor', $o), ['vendedor_id' => $this->dono->id])->assertForbidden();
        $outra = Unidade::factory()->create();
        $this->entrarComo($this->usuario(Papel::Gerente, $outra))->post(route('ordens.vendedor', $o), ['vendedor_id' => $this->dono->id])->assertForbidden();
        // Recepção/mecânico ou gerente de outra unidade não podem ser vendedor.
        $this->entrarComo($this->gerente)->post(route('ordens.vendedor', $o), ['vendedor_id' => $this->recepcao->id])->assertSessionHasErrors('vendedor_id');
        $this->entrarComo($this->gerente)->post(route('ordens.vendedor', $o), ['vendedor_id' => $this->usuario(Papel::Gerente, $outra)->id])->assertSessionHasErrors('vendedor_id');

        $this->entrarComo($this->gerente)->post(route('ordens.vendedor', $o), ['vendedor_id' => $this->dono->id])->assertSessionHasNoErrors();
        $this->assertSame($this->dono->id, $o->fresh()->vendedor_id);
        $ev = AuditEvent::where('acao', 'vendedor_definido')->where('entidade_id', $o->id)->latest('id')->first();
        $this->assertSame([$this->gerente->id, $this->dono->id, 'manual'], [$ev->antes['vendedor_id'], $ev->depois['vendedor_id'], $ev->depois['origem']]);

        $this->entrarComo($this->recepcao)->get(route('ordens.show', $o))->assertSee('Vendedor')->assertSee($this->dono->name);
        $this->entrarComo($this->recepcao)->get(route('ordens.impressao', $o))->assertSee($this->dono->name);
        $this->entrarComo($this->mecanico)->get(route('ordens.show', $o))->assertDontSee('Vendedor');
    }

    public function test_os_impressa_recepcao_emite_com_campos_obrigatorios_mecanico_403_e_portal_sem_dados_internos(): void
    {
        // Regressão da falha intermitente: nome do Faker com apóstrofo sai escapado (&#039;) no HTML.
        $this->gerente->forceFill(['name' => "Dasia O'Kon"])->save();
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresaValida(['logo' => $this->foto('logo.png')]))->assertSessionHasNoErrors();
        $o = $this->ordemAprovada();
        $o->cliente->update(\App\Support\Endereco::normalizar(self::ENDERECO));
        app(\App\Services\PrazosEntrega::class)->definir($o->fresh(), $this->gerente, diasUteis: 5);
        $o = $o->fresh();

        $this->entrarComo($this->mecanico)->get(route('ordens.impressao', $o))->assertForbidden();
        $this->entrarComo($this->mecanico)->get(route('ordens.show', $o))->assertDontSee('Imprimir OS');
        $this->assertFalse(AuditEvent::where('acao', 'os_impressa')->exists());

        $this->entrarComo($this->recepcao)->get(route('ordens.show', $o))->assertSee('Imprimir OS');
        $r = $this->entrarComo($this->recepcao)->get(route('ordens.impressao', $o))->assertOk();
        foreach (['Rodas Brilho', '11.222.333/0001-81', '(61) 3333-4444', 'Rua das Rodas', $o->numero, 'Mariana Costa', '(61) 99123-4567', 'Placa RDA1B23',
            '4', 'Reforma geral de roda aro 17', 'R$ 250,00', 'Desconto', 'Total aprovado', 'R$ 1.000,00', 'Recebimentos', 'Dinheiro', 'R$ 400,00', 'Saldo a pagar', 'R$ 600,00',
            $o->entrega_prevista_em->format('d/m/Y'), 'NOTA-INTERNA-RECEPCAO', 'Vendedor', 'Cliente', e($this->gerente->name), 'Não é documento fiscal', 'data:image/png;base64,', '@media print'] as $esperado) {
            $r->assertSee($esperado, false);
        }
        $ev = AuditEvent::where('acao', 'os_impressa')->sole();
        $this->assertSame([$this->recepcao->id, 'equipe'], [$ev->ator_id, $ev->depois['canal']]);

        $outra = Unidade::factory()->create();
        $this->entrarComo($this->usuario(Papel::Recepcao, $outra))->get(route('ordens.impressao', $o))->assertForbidden();

        $token = app(Orcamentos::class)->novoLink($o, $this->gerente);
        $this->get(route('portal.show', $token))->assertSee(route('portal.impressao', $token), false);
        $p = $this->get(route('portal.impressao', $token))->assertOk()->assertSee('Não é documento fiscal')->assertSee('R$ 1.000,00')->assertDontSee('NOTA-INTERNA-RECEPCAO');
        $this->assertSame('no-store, private', $p->headers->get('Cache-Control'));
        $this->assertSame('cliente', AuditEvent::where('acao', 'os_impressa')->latest('id')->first()->ator_tipo);
        $this->get(route('portal.impressao', str_repeat('x', 48)))->assertNotFound();
    }
}
