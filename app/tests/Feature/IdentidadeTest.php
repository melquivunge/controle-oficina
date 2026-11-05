<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\AuditEvent;
use App\Models\Unidade;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentidadeTest extends TestCase
{
    public function test_login_regenera_sessao_registra_auditoria_e_rejeita_senha_errada(): void
    {
        $user = User::factory()->create(['email' => 'ana@oficina.test']);

        $this->post('/entrar', ['email' => 'ana@oficina.test', 'password' => 'errada'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/entrar', ['email' => 'ANA@oficina.test', 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_events', ['acao' => 'login', 'ator_id' => $user->id]);
        $this->assertDatabaseHas('audit_events', ['acao' => 'login_recusado', 'entidade_id' => $user->id]);
    }

    public function test_login_tem_limite_de_tentativas(): void
    {
        User::factory()->create(['email' => 'ana@oficina.test']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/entrar', ['email' => 'ana@oficina.test', 'password' => 'x']);
        }
        $this->post('/entrar', ['email' => 'ana@oficina.test', 'password' => 'password'])->assertStatus(429);
    }

    public function test_conta_desativada_nao_entra_e_sessao_aberta_e_revogada(): void
    {
        $user = User::factory()->create(['email' => 'joao@oficina.test']);
        $this->entrarComo($user)->get('/')->assertOk();

        $user->forceFill(['ativo' => false])->save();
        $this->get('/')->assertRedirect('/entrar');
        $this->assertGuest();

        $this->post('/entrar', ['email' => 'joao@oficina.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_proprietario_desativa_usuario_com_motivo_e_auditoria(): void
    {
        $dono = User::factory()->proprietario()->create();
        $alvo = User::factory()->create();

        $this->entrarComo($dono)->post("/configuracoes/usuarios/{$alvo->id}/ativo", [])->assertSessionHasErrors('motivo');
        $this->entrarComo($dono)->post("/configuracoes/usuarios/{$alvo->id}/ativo", ['motivo' => 'Saiu da empresa'])->assertRedirect();

        $this->assertFalse($alvo->fresh()->ativo);
        $evento = AuditEvent::where('acao', 'usuario_desativado')->sole();
        $this->assertSame($dono->id, $evento->ator_id);
        $this->assertSame('Saiu da empresa', $evento->motivo);
        $this->assertSame($alvo->unidade_id, $evento->unidade_id);
    }

    /** @return array<string, array{Papel}> */
    public static function papeisNaoProprietarios(): array
    {
        return ['gerente' => [Papel::Gerente], 'recepção' => [Papel::Recepcao], 'mecânico' => [Papel::Mecanico]];
    }

    #[DataProvider('papeisNaoProprietarios')]
    public function test_somente_proprietario_acessa_configuracoes_mesmo_por_requisicao_direta(Papel $papel): void
    {
        $user = $this->usuario($papel);
        $unidade = $user->unidade;

        $this->entrarComo($user)->get('/configuracoes/usuarios')->assertForbidden();
        $this->entrarComo($user)->post('/configuracoes/unidades', ['codigo' => 'XYZ', 'nome' => 'Invasora'])->assertForbidden();
        $this->entrarComo($user)->put("/configuracoes/unidades/{$unidade->id}", ['codigo' => 'X', 'nome' => 'Mudou'])->assertForbidden();
        $this->entrarComo($user)->put("/configuracoes/usuarios/{$user->id}", ['name' => 'Eu', 'email' => $user->email, 'papel' => 'proprietario'])->assertForbidden();

        $this->assertSame(Papel::from($papel->value), $user->fresh()->papel);
        $this->assertDatabaseMissing('unidades', ['codigo' => 'XYZ']);
    }

    public function test_configuracao_exige_reautenticacao_recente(): void
    {
        $dono = User::factory()->proprietario()->create();
        $this->actingAs($dono)->withSession(['dois_fatores_ok' => $dono->id])
            ->get('/configuracoes/unidades')->assertRedirect('/confirmar-senha');

        $this->post('/confirmar-senha', ['password' => 'errada'])->assertSessionHasErrors('password');
        $this->post('/confirmar-senha', ['password' => 'password'])->assertRedirect();
        $this->get('/configuracoes/unidades')->assertOk();
    }

    public function test_proprietario_sem_chave_2fa_nao_se_autocadastra_pela_web(): void
    {
        $dono = User::factory()->proprietario()->create();
        $this->actingAs($dono)->get('/')->assertRedirect('/entrar');
        $this->assertGuest();
        $this->get('/dois-fatores/configurar')->assertNotFound();
    }

    public function test_proprietario_gera_e_entrega_chave_ao_criar_outro_proprietario(): void
    {
        $dono = User::factory()->proprietario()->create();
        $this->entrarComo($dono)->post('/configuracoes/usuarios', [
            'name' => 'Sócia', 'email' => 'socia@oficina.test', 'papel' => 'proprietario',
            'password' => 'senha12345', 'password_confirmation' => 'senha12345',
        ])->assertRedirect('/configuracoes/usuarios')->assertSessionHas('chave_2fa');

        $socia = User::where('email', 'socia@oficina.test')->sole();
        $this->assertSame(session('chave_2fa')['segredo'], $socia->two_factor_secret);
        $this->assertNotSame($socia->two_factor_secret, DB::table('users')->where('id', $socia->id)->value('two_factor_secret'), 'segredo deve ficar criptografado no banco');
        $this->assertDatabaseHas('audit_events', ['acao' => 'dois_fatores_chave_gerada', 'entidade_id' => $socia->id]);
        $this->assertStringNotContainsString($socia->two_factor_secret, json_encode(AuditEvent::all()->toArray()));
    }

    public function test_codigo_2fa_nao_pode_ser_reutilizado(): void
    {
        $segredo = Totp::novoSegredo();
        $dono = User::factory()->proprietario()->create();
        $dono->forceFill(['two_factor_secret' => $segredo, 'two_factor_confirmed_at' => now()])->save();
        $codigo = Totp::codigo($segredo, time());

        $this->actingAs($dono)->post('/dois-fatores', ['codigo' => $codigo])->assertRedirect();
        $this->flushSession();
        $this->actingAs($dono)->post('/dois-fatores', ['codigo' => $codigo])->assertSessionHasErrors('codigo');
    }

    public function test_unidade_inativa_revoga_acesso_dos_seus_usuarios(): void
    {
        $recepcao = $this->usuario(Papel::Recepcao);
        $this->entrarComo($recepcao)->get('/')->assertOk();
        $recepcao->unidade->update(['ativa' => false]);
        $this->get('/')->assertRedirect('/entrar');
        $this->assertGuest();
        $this->assertFalse($recepcao->fresh()->pode('registrar_entrada'));
    }

    public function test_troca_de_senha_derruba_sessoes_abertas(): void
    {
        $user = User::factory()->create();
        $this->entrarComo($user)->get('/')->assertOk();
        $user->forceFill(['password' => 'nova-senha-123'])->save();
        $this->get('/')->assertRedirect('/entrar');
    }

    public function test_login_com_email_inexistente_responde_igual(): void
    {
        $this->post('/entrar', ['email' => 'ninguem@oficina.test', 'password' => 'x'])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos, ou conta desativada.']);
    }

    public function test_proprietario_com_2fa_passa_pelo_desafio_a_cada_login(): void
    {
        $segredo = Totp::novoSegredo();
        $dono = User::factory()->proprietario()->create();
        $dono->forceFill(['two_factor_secret' => $segredo, 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($dono)->get('/auditoria')->assertRedirect('/dois-fatores');
        $this->post('/dois-fatores', ['codigo' => '123'])->assertSessionHasErrors('codigo');
        $this->post('/dois-fatores', ['codigo' => Totp::codigo($segredo, time())])->assertRedirect();
        $this->get('/auditoria')->assertOk();
    }

    public function test_gerente_ve_somente_auditoria_da_sua_unidade_e_filtro_de_outra_e_negado(): void
    {
        [$a, $b] = Unidade::factory()->count(2)->create();
        $gerente = $this->usuario(Papel::Gerente, $a);
        $outro = $this->usuario(Papel::Recepcao, $b);
        AuditEvent::create(['acao' => 'evento_a', 'unidade_id' => $a->id, 'ator_id' => $gerente->id]);
        AuditEvent::create(['acao' => 'evento_b', 'unidade_id' => $b->id, 'ator_id' => $outro->id]);

        $this->entrarComo($gerente)->get('/auditoria')->assertOk()->assertSee('Evento a')->assertDontSee('Evento b');
        $this->entrarComo($gerente)->get("/auditoria?unidade={$b->id}")->assertForbidden();
        $this->entrarComo($this->usuario(Papel::Mecanico, $a))->get('/auditoria')->assertForbidden();
    }

    public function test_auditoria_e_somente_insercao_no_banco(): void
    {
        $e = AuditEvent::create(['acao' => 'teste']);
        try {
            DB::table('audit_events')->where('id', $e->id)->update(['acao' => 'alterado']);
            $this->fail('UPDATE deveria ser rejeitado');
        } catch (QueryException $ex) {
            $this->assertStringContainsString('somente inserção', $ex->getMessage());
        }
        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $e->id)->delete();
    }

    public function test_criacao_de_usuario_valida_unidade_e_nao_audita_senha(): void
    {
        $dono = User::factory()->proprietario()->create();
        $unidade = Unidade::factory()->create();

        $this->entrarComo($dono)->post('/configuracoes/usuarios', [
            'name' => 'Paula', 'email' => 'paula@oficina.test', 'papel' => 'mecanico', 'password' => 'senha12345', 'password_confirmation' => 'senha12345',
        ])->assertSessionHasErrors('unidade_id');

        $this->entrarComo($dono)->post('/configuracoes/usuarios', [
            'name' => 'Paula', 'email' => 'paula@oficina.test', 'papel' => 'recepcao', 'unidade_id' => $unidade->id,
            'permissoes_extras' => ['aplicar_desconto', 'inventada'], 'password' => 'senha12345', 'password_confirmation' => 'senha12345',
        ])->assertSessionHasErrors('permissoes_extras.1');

        $this->entrarComo($dono)->post('/configuracoes/usuarios', [
            'name' => 'Paula', 'email' => 'paula@oficina.test', 'papel' => 'recepcao', 'unidade_id' => $unidade->id,
            'permissoes_extras' => ['aplicar_desconto'], 'password' => 'senha12345', 'password_confirmation' => 'senha12345',
        ])->assertRedirect('/configuracoes/usuarios');

        $paula = User::where('email', 'paula@oficina.test')->sole();
        $this->assertTrue($paula->pode('aplicar_desconto'));
        $this->assertFalse($paula->pode('cancelar_ordem'));
        $evento = AuditEvent::where('acao', 'usuario_criado')->sole();
        $this->assertArrayNotHasKey('password', $evento->depois);
        $this->assertStringNotContainsString('senha12345', json_encode($evento->depois));
    }

    public function test_banco_rejeita_usuario_nao_proprietario_sem_unidade(): void
    {
        $this->expectException(QueryException::class);
        User::factory()->create(['papel' => Papel::Gerente, 'unidade_id' => null]);
    }

    public function test_navegacao_mostra_somente_itens_permitidos(): void
    {
        $mecanico = $this->usuario(Papel::Mecanico);
        $this->entrarComo($mecanico)->get('/fila')->assertOk()->assertDontSee('Usuários')->assertDontSee('Histórico de ações');

        $dono = User::factory()->proprietario()->create();
        $this->entrarComo($dono)->get('/')->assertOk()->assertSee('Usuários e permissões')->assertSee('Auditoria');
    }

    public function test_formulario_de_novo_usuario_nao_sugere_proprietario(): void
    {
        $dono = User::factory()->proprietario()->create();
        $html = $this->entrarComo($dono)->get('/configuracoes/usuarios/novo')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="recepcao"\s+selected/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="proprietario"\s+selected/', $html);
    }
}
