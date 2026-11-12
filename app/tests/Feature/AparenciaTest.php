<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\AuditEvent;
use App\Models\Empresa;
use App\Models\Tema;
use App\Models\Unidade;
use App\Models\User;
use App\Support\Aparencia;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Aparência (31/10/2026): temas claro/escuro/sistema, temas personalizados, nome do sistema, descrição e logo na tela. */
class AparenciaTest extends TestCase
{
    private User $dono;
    private User $recepcao;
    private User $mecanico;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $unidade = Unidade::factory()->create();
        $this->dono = $this->usuario(Papel::Proprietario);
        $this->recepcao = $this->usuario(Papel::Recepcao, $unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $unidade);
    }

    private function tema(array $extra = []): array
    {
        return $extra + ['nome' => 'Oficina Noite', 'base' => 'escuro', 'cor_marca' => '#FFD400', 'cor_menu' => '#000000'];
    }

    private function empresa(array $extra = []): array
    {
        return $extra + ['razao_social' => 'Rodas Brilho Ltda', 'nome_fantasia' => 'Rodas Brilho', 'cnpj' => '11.222.333/0001-81', 'telefone' => '(61) 3333-4444',
            'cep' => '70.040-010', 'logradouro' => 'Rua das Rodas', 'numero' => '17', 'bairro' => 'Asa Sul', 'cidade' => 'Brasília', 'uf' => 'df'];
    }

    /** 12/11/2026: tema único da empresa — o mesmo na entrada, para todos os papéis e no link do cliente; sem escolha pessoal. */
    public function test_tema_da_empresa_vale_para_todos_e_nao_ha_tema_pessoal(): void
    {
        $this->entrarComo($this->recepcao)->get(route('conta'))->assertOk()->assertSee('data-tema="claro"', false)->assertSee('Quem escolhe é o dono')
            ->assertDontSee('Salvar aparência');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('conta.tema'));

        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresa(['tema_padrao' => 'escuro']))->assertSessionHasNoErrors();
        foreach ([$this->dono, $this->recepcao, $this->mecanico] as $u) {
            $this->entrarComo($u)->get(route('conta'))->assertSee('data-tema="escuro"', false);
        }
        // Coluna antiga preenchida não muda mais nada.
        $this->recepcao->forceFill(['tema' => 'claro'])->save();
        $this->entrarComo($this->recepcao->fresh())->get(route('conta'))->assertSee('data-tema="escuro"', false);
        $this->entrarComo($this->dono)->get(route('conta'))->assertSee('Identidade da empresa › Tema padrão');
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $this->mecanico->id)->update(['tema' => 'qualquer"><script>']);
    }

    public function test_migracao_leva_o_tema_pessoal_do_dono_para_a_empresa(): void
    {
        $this->entrarComo($this->dono)->post(route('temas.store'), $this->tema())->assertSessionHasNoErrors();
        $tema = Tema::sole();
        Empresa::atual()->forceFill(['tema_padrao' => null])->save();
        $this->dono->forceFill(['tema' => $tema->chave()])->save();
        $this->recepcao->forceFill(['tema' => 'escuro'])->save();

        (require database_path('migrations/2026_11_12_000200_tema_unico_da_empresa.php'))->up();

        $this->assertSame($tema->chave(), Empresa::atual()->fresh()->tema_padrao);
        $this->assertSame(0, User::whereNotNull('tema')->count());
        auth()->logout();
        $this->flushSession();
        $this->get(route('login'))->assertSee('--marca:#ffd400', false);
    }

    public function test_somente_proprietario_cria_tema_e_cores_vao_com_texto_legivel(): void
    {
        foreach ([$this->recepcao, $this->mecanico] as $u) {
            $this->entrarComo($u)->post(route('temas.store'), $this->tema())->assertForbidden();
        }
        $this->assertSame(0, Tema::count());

        $this->entrarComo($this->dono)->post(route('temas.store'), $this->tema(['cor_marca' => 'amarelo']))->assertSessionHasErrors('cor_marca', null, 'tema');
        $this->entrarComo($this->dono)->post(route('temas.store'), $this->tema(['cor_menu' => '#000;}body{x']))->assertSessionHasErrors('cor_menu', null, 'tema');
        $this->entrarComo($this->dono)->post(route('temas.store'), $this->tema())->assertSessionHasNoErrors();
        $this->entrarComo($this->dono)->post(route('temas.store'), $this->tema())->assertSessionHasErrors('nome', null, 'tema');

        $tema = Tema::sole();
        $this->assertSame(['escuro', '#ffd400', '#000000'], [$tema->base, $tema->cor_marca, $tema->cor_menu]);
        $this->assertSame(1, AuditEvent::where('acao', 'tema_criado')->count());

        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresa(['tema_padrao' => $tema->chave()]))->assertSessionHasNoErrors();
        $this->entrarComo($this->recepcao)->get(route('conta'))->assertOk()
            ->assertSee('data-tema="escuro"', false)
            // amarelo pede texto escuro; preto pede texto branco
            ->assertSee('--marca:#ffd400;--marca-contraste:#000000;--menu-fundo:#000000;--menu-texto:#ffffff', false);

        $this->entrarComo($this->dono)->put(route('temas.update', $tema), $this->tema(['nome' => 'Dia', 'base' => 'claro', 'cor_marca' => '#7a00ff']))->assertSessionHasNoErrors();
        $this->assertSame(['Dia', 'claro', '#7a00ff'], [$tema->fresh()->nome, $tema->fresh()->base, $tema->fresh()->cor_marca]);
        $this->entrarComo($this->recepcao)->delete(route('temas.destroy', $tema))->assertForbidden();
    }

    public function test_excluir_tema_devolve_a_empresa_ao_padrao(): void
    {
        $this->entrarComo($this->dono)->post(route('temas.store'), $this->tema())->assertSessionHasNoErrors();
        $tema = Tema::sole();
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresa(['tema_padrao' => $tema->chave()]))->assertSessionHasNoErrors();

        $this->entrarComo($this->mecanico)->get(route('conta'))->assertSee('data-tema="escuro"', false)->assertSee('--menu-fundo:#000000', false);

        $this->entrarComo($this->dono)->delete(route('temas.destroy', $tema))->assertSessionHasNoErrors();
        $this->assertNull(Empresa::atual()->tema_padrao);
        $this->assertSame(1, AuditEvent::where('acao', 'tema_excluido')->count());
        $this->entrarComo($this->recepcao)->get(route('conta'))->assertSee('data-tema="claro"', false)->assertDontSee('--menu-fundo:', false);
    }

    public function test_tema_padrao_da_empresa_vale_na_tela_de_entrada(): void
    {
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresa(['tema_padrao' => 'escuro']))->assertSessionHasNoErrors();
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresa(['tema_padrao' => 'neon']))->assertSessionHasErrors('tema_padrao');
        auth()->logout();
        $this->flushSession();
        $this->get(route('login'))->assertOk()->assertSee('data-tema="escuro"', false);
    }

    public function test_nome_do_sistema_descricao_e_logo_aparecem_no_menu_e_na_entrada(): void
    {
        $this->entrarComo($this->recepcao)->get(route('conta'))->assertSee('Controle de Oficina');

        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresa([
            'nome_sistema' => '  Rodas Brilho Gestão ', 'descricao' => 'Estética automotiva e reforma de rodas desde 1998.', 'logo' => $this->foto('logo.png'),
        ]))->assertSessionHasNoErrors();
        $e = Empresa::atual();
        $this->assertSame(['Rodas Brilho Gestão', 'Estética automotiva e reforma de rodas desde 1998.'], [$e->nome_sistema, $e->descricao]);
        $this->assertSame('Rodas Brilho Gestão', AuditEvent::where('acao', 'empresa_alterada')->latest('id')->first()->depois['nome_sistema']);

        $this->entrarComo($this->recepcao)->get(route('conta'))->assertOk()
            ->assertSee('<title>Minha conta · Rodas Brilho Gestão</title>', false)
            ->assertSee('brand-mark-logo', false)
            ->assertDontSee('Controle de Oficina');

        auth()->logout();
        $this->flushSession();
        $this->get(route('login'))->assertOk()
            ->assertSee('Rodas Brilho Gestão')
            ->assertSee('Estética automotiva e reforma de rodas desde 1998.')
            ->assertSee(route('empresa.logo'), false);

        // Limites: nome curto demais e descrição longa recusados; vazio volta ao padrão.
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresa(['nome_sistema' => 'X']))->assertSessionHasErrors('nome_sistema');
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresa(['descricao' => str_repeat('a', 301)]))->assertSessionHasErrors('descricao');
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresa(['nome_sistema' => '', 'descricao' => '']))->assertSessionHasNoErrors();
        $this->assertSame('Controle de Oficina', Empresa::atual()->nomeSistema());
    }

    public function test_nome_do_sistema_e_escapado(): void
    {
        $this->entrarComo($this->dono)->put(route('empresa.update'), $this->empresa(['nome_sistema' => '<script>alert(1)</script>']))->assertSessionHasNoErrors();
        $this->entrarComo($this->recepcao)->get(route('conta'))->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_texto_sobre_cor_escolhe_maior_contraste(): void
    {
        foreach (['#ffffff' => '#000000', '#ffd400' => '#000000', '#7f7f7f' => '#000000', '#595959' => '#ffffff', '#000000' => '#ffffff', '#0f1b2d' => '#ffffff', '#1f9d6b' => '#000000'] as $fundo => $texto) {
            $this->assertSame($texto, Aparencia::textoSobre($fundo), $fundo);
            $this->assertGreaterThanOrEqual(4.5, Aparencia::contraste($fundo, $texto), $fundo);
        }
    }

    public function test_engrenagem_leva_a_configuracoes_da_empresa_com_temas_prontos(): void
    {
        $html = $this->entrarComo($this->dono)->get(route('conta'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('empresa.edit'), '#').'"[^>]*>.*?Configurações da empresa#s', $html);
        $this->assertStringContainsString('href="'.route('empresa.edit').'" aria-label="Configurações da empresa"', $html);

        $this->entrarComo($this->dono)->get(route('empresa.edit'))->assertOk()
            ->assertSeeInOrder(['Claro', 'Escuro', 'Seguir o aparelho', 'Personalizados'])
            ->assertSee('Tema pronto · padrão da empresa');
    }
}
