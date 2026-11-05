<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Pagamento;
use App\Models\Unidade;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Tests\TestCase;

/** Layout v2 (menu agrupado, sino, menu do usuário), dashboard com período/CSV e página Minha conta. */
class PainelLayoutTest extends TestCase
{
    public function test_dashboard_separa_financeiro_por_papel_e_unidade_e_exporta_csv(): void
    {
        $a = Unidade::factory()->create(['nome' => '=Oficina A']);
        $b = Unidade::factory()->create(['nome' => 'Oficina B']);
        $rec = $this->usuario(Papel::Recepcao, $a);
        $ger = $this->usuario(Papel::Gerente, $a);
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $a->id, 'nome' => 'Mariana', 'telefone' => '61991234567', 'placa' => 'QWE4R56', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->usuario(Papel::Mecanico, $a));
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $ger, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '250']]);
        $s->enviar($v, $ger);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
        app(Financeiro::class)->registrarManual($o->fresh(), $rec, 'dinheiro', 10000);

        $this->entrarComo($ger)->get('/?periodo=7')->assertOk()
            ->assertSee('Recebido (caixa)')->assertSee('R$ 100,00')->assertSee('Recebido por forma')->assertSee('Exportar CSV')
            ->assertSee('Pontos de atenção')->assertSee($o->numero);
        $this->entrarComo($rec)->get('/')->assertOk()->assertDontSee('Recebido (caixa)')->assertDontSee('Exportar CSV')->assertSee('No pátio agora')->assertSee($o->numero);
        $this->entrarComo($ger)->get("/?unidade={$b->id}")->assertForbidden();
        $this->entrarComo($ger)->get('/?periodo=999')->assertSessionHasErrors('periodo');

        $csv = $this->entrarComo($ger)->get('/painel/exportar?periodo=30')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=Oficina A", $csv, 'nome começando com = é neutralizado');
        $this->assertStringContainsString('100,00', $csv);
        $this->assertStringNotContainsString('Oficina B', $csv);
        $this->entrarComo($rec)->get('/painel/exportar')->assertForbidden();
    }

    public function test_sino_mostra_pendencias_so_das_unidades_do_gerente(): void
    {
        $a = Unidade::factory()->create();
        $b = Unidade::factory()->create();
        $ger = $this->usuario(Papel::Gerente, $a);
        foreach ([$a, $b] as $i => $u) {
            $rec = $this->usuario(Papel::Recepcao, $u);
            $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'C'.$i, 'telefone' => '6199123456'.$i, 'placa' => 'ABC1D2'.$i, 'motivo' => 'x']);
            Pagamento::create(['ordem_id' => $o->id, 'unidade_id' => $u->id, 'meio' => 'pix', 'valor_centavos' => 5000 + $i, 'status' => 'em_revisao', 'recebido_em' => now(), 'motivo_revisao' => 'teste', 'id_provedor' => 'E2E-teste-'.$i]);
        }
        $html = $this->entrarComo($ger)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Notificações: 1 abertas', $html);
        $this->assertStringContainsString('R$ 50,00', $html);
        $this->assertStringNotContainsString('R$ 50,01', $html);
        $this->entrarComo($this->usuario(Papel::Recepcao, $a))->get('/')->assertOk()->assertDontSee('Notificações: 1');
    }

    public function test_minha_conta_troca_senha_com_senha_atual_e_auditoria(): void
    {
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $rec->forceFill(['password' => 'SenhaAntiga123'])->save();
        $this->entrarComo($rec)->get('/conta')->assertOk()->assertSee($rec->email)->assertSee('Alterar senha');
        $this->entrarComo($rec)->put('/conta/senha', ['senha_atual' => 'errada', 'password' => 'NovaSenha456', 'password_confirmation' => 'NovaSenha456'])->assertSessionHasErrors('senha_atual');
        $this->entrarComo($rec)->put('/conta/senha', ['senha_atual' => 'SenhaAntiga123', 'password' => 'curta', 'password_confirmation' => 'curta'])->assertSessionHasErrors('password');
        $this->entrarComo($rec)->put('/conta/senha', ['senha_atual' => 'SenhaAntiga123', 'password' => 'NovaSenha456', 'password_confirmation' => 'NovaSenha456'])->assertRedirect('/conta');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NovaSenha456', $rec->fresh()->password));
        $this->assertDatabaseHas('audit_events', ['acao' => 'senha_alterada']);
    }

    public function test_lista_de_clientes_mostra_so_atendidos_nas_unidades_do_usuario(): void
    {
        $a = Unidade::factory()->create();
        $b = Unidade::factory()->create();
        $recA = $this->usuario(Papel::Recepcao, $a);
        app(Entradas::class)->registrar($recA, ['unidade_id' => $a->id, 'nome' => 'Joana Lima', 'telefone' => '61991230001', 'placa' => 'JOA1A11', 'motivo' => 'x']);
        app(Entradas::class)->registrar($this->usuario(Papel::Recepcao, $b), ['unidade_id' => $b->id, 'nome' => 'Pedro Souza', 'telefone' => '61991230002', 'placa' => 'PED2B22', 'motivo' => 'x']);

        $this->entrarComo($recA)->get('/clientes')->assertOk()->assertSee('Joana Lima')->assertSee('JOA1A11')->assertDontSee('Pedro Souza')->assertSee('1 cliente');
        $this->entrarComo($recA)->get('/clientes?busca=Pedro')->assertOk()->assertSee('Pedro Souza');
        $this->entrarComo(\App\Models\User::factory()->proprietario()->create())->get('/clientes')->assertOk()->assertSee('Joana Lima')->assertSee('Pedro Souza');
    }

    public function test_perfil_proprio_com_foto_telefone_cargo_e_auditoria(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $outro = $this->usuario(Papel::Mecanico, $u);
        $bloco = fn ($t, $d) => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));
        $png = "\x89PNG\r\n\x1a\n".$bloco('IHDR', pack('NNCCCCC', 64, 64, 8, 2, 0, 0, 0)).$bloco('IDAT', gzcompress(str_repeat("\0".str_repeat("\x1f\x9d\x6b", 64), 64))).$bloco('IEND', '');
        $foto = \Illuminate\Http\UploadedFile::fake()->createWithContent('eu.png', $png);

        $this->entrarComo($rec)->put('/conta/perfil', ['name' => 'Ana Paula Souza', 'telefone' => '(61) 99123-4567', 'cargo' => 'Recepcionista', 'foto' => $foto])->assertRedirect('/conta');
        $rec->refresh();
        $this->assertSame(['Ana Paula Souza', '61991234567', 'Recepcionista'], [$rec->name, $rec->telefone, $rec->cargo]);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($rec->foto_caminho);
        $this->assertDatabaseHas('audit_events', ['acao' => 'perfil_alterado']);
        $this->entrarComo($outro)->get('/')->assertRedirect();
        $this->entrarComo($outro)->get("/usuarios/{$rec->id}/foto")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->entrarComo($rec)->get('/conta')->assertOk()->assertSee('/usuarios/'.$rec->id.'/foto', false)->assertSee('Recepcionista');

        $this->entrarComo($rec)->put('/conta/perfil', ['name' => 'A', 'telefone' => '123'])->assertSessionHasErrors(['name', 'telefone']);
        $this->entrarComo($rec)->put('/conta/perfil', ['name' => 'Ana', 'foto' => \Illuminate\Http\UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('foto');
        $antiga = $rec->foto_caminho;
        $this->entrarComo($rec)->put('/conta/perfil', ['name' => 'Ana Paula Souza', 'remover_foto' => '1'])->assertRedirect('/conta');
        $this->assertNull($rec->fresh()->foto_caminho);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($antiga);
        $this->entrarComo($outro)->get("/usuarios/{$rec->id}/foto")->assertNotFound();
        $this->assertSame('Recepcao', \App\Models\User::find($rec->id)->papel->name, 'papel não muda pelo perfil');
    }

    public function test_duracao_curta(): void
    {
        $this->assertSame(['0 min', '59 min', '1 h', '2 h 5 min', '1 d', '1 d 3 h'], array_map([\App\Support\Duracao::class, 'curta'], [-3, 59, 60, 125, 1440, 1440 + 185]));
    }

    public function test_recepcao_ve_recebimentos_sem_fechamento_e_mecanico_sem_item_painel(): void
    {
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $ger = $this->usuario(Papel::Gerente, $u);
        $html = $this->entrarComo($rec)->get("/caixa?unidade={$u->id}&aba=fechamento")->assertOk()->getContent();
        $this->assertStringContainsString('<h1>Recebimentos</h1>', $html);
        $this->assertStringContainsString('A receber', $html);
        $this->assertStringNotContainsString('Fechamento do dia', $html);
        $this->assertStringNotContainsString('Esperado na gaveta', $html);
        $this->assertStringNotContainsString('Dinheiro esperado na gaveta', $html);
        $this->entrarComo($rec)->post('/caixa/fechar', ['unidade' => $u->id, 'data' => now('America/Sao_Paulo')->format('Y-m-d'), 'contado' => '0,00'])->assertForbidden();
        $this->entrarComo($ger)->get("/caixa?unidade={$u->id}&aba=fechamento")->assertOk()->assertSee('Fechamento do dia')->assertSee('Caixa e fechamento');

        $mec = $this->entrarComo($this->usuario(Papel::Mecanico, $u))->get('/fila')->assertOk()->getContent();
        preg_match('#<nav class="nav".*?</nav>#s', $mec, $menu);
        $this->assertStringNotContainsString('href="'.route('painel').'"', $menu[0], 'item Painel some do menu do mecânico');
        $this->assertStringContainsString('Fila do mecânico', $menu[0]);
        $this->assertStringContainsString('href="'.route('painel.mecanico').'"', $menu[0], 'mecânico tem o próprio painel');
        $this->entrarComo($rec)->get('/meu-painel')->assertForbidden();
    }

    public function test_painel_do_mecanico_mostra_prazos_sem_valores_nem_contato(): void
    {
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $ger = $this->usuario(Papel::Gerente, $u);
        $mec = $this->usuario(Papel::Mecanico, $u);
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'Carla Dias', 'telefone' => '61991239999', 'placa' => 'MEC1A23', 'motivo' => 'Reforma geral aro 17']);
        app(Ordens::class)->iniciarDiagnostico($o, $mec);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($o, $ger, [['descricao' => 'Reforma aro 17', 'quantidade' => '4', 'preco' => '300']]);
        $s->enviar($v, $ger);
        $s->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
        app(\App\Services\PrazosEntrega::class)->definir($o->fresh(), $ger, 1);
        $this->travel(5)->days();

        $html = $this->entrarComo($mec)->get('/meu-painel')->assertOk()->getContent();
        $this->assertStringContainsString($o->numero, $html);
        $this->assertStringContainsString('Atrasados', $html);
        $this->assertStringContainsString('servico atrasado', $html);
        $this->assertStringNotContainsString('R$', $html);
        $this->assertStringNotContainsString('99123-9999', $html);
        $this->assertStringNotContainsString('61991239999', $html);
    }
}
