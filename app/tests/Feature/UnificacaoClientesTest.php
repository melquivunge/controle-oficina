<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Cliente;
use App\Models\Veiculo;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Unificação sem perder dados (09/11/2026): tudo vai para a ficha principal; conflitos não são unificados sozinhos. */
class UnificacaoClientesTest extends TestCase
{
    private function cliente(array $a): Cliente
    {
        $c = new Cliente($a + ['telefone' => '']);
        $c->forceFill(array_intersect_key($a, array_flip(['documento', 'legado'])))->save();

        return $c;
    }

    public function test_unificar_traz_tudo_e_nao_apaga(): void
    {
        $principal = $this->cliente(['nome' => 'José Silva', 'telefone' => '61999990000']);
        $dup = $this->cliente(['nome' => 'JOSE  SILVA', 'documento' => '12345678909', 'email' => 'j@x.com', 'cidade' => 'Brasília', 'uf' => 'DF', 'observacoes' => 'cliente antigo', 'legado' => ['RG' => '123 SSP']]);
        $v = new Veiculo(['placa' => 'ABC1D23']);
        $v->cliente_id = $dup->id;
        $v->save();
        DB::table('legado_registros')->insert(['origem' => 'sga', 'tabela' => 'TBL_OS', 'linha' => 1, 'codigo' => '9', 'grupo' => 'OS:9', 'cliente_id' => $dup->id, 'dados' => json_encode(['CODIGO' => '9', 'TOTAL' => '100.00'])]);

        $dono = $this->usuario(Papel::Proprietario);
        $this->entrarComo($dono)->get(route('clientes.show', $principal))->assertSee('Possível cadastro duplicado')->assertSee('#'.$dup->id); // mesmo nome
        $this->post(route('clientes.mesclar', $principal), ['duplicado_id' => $dup->id, 'motivo' => 'Mesma pessoa'])->assertRedirect();

        $p = $principal->fresh();
        $this->assertSame(['61999990000', '12345678909', 'j@x.com', 'Brasília', 'DF', 'cliente antigo'], [$p->telefone, $p->documento, $p->email, $p->cidade, $p->uf, $p->observacoes]);
        $this->assertSame('123 SSP', $p->legado['_unificados'][0]['legado']['RG'], 'cópia integral do duplicado');
        $this->assertSame($p->id, $v->fresh()->cliente_id);
        $this->assertSame($p->id, DB::table('legado_registros')->value('cliente_id'), 'histórico antigo vem junto');
        $this->assertSame($p->id, $dup->fresh()->mesclado_em_id, 'duplicado marcado, não apagado');
        $this->get(route('clientes.show', $p))->assertSee('Histórico no sistema antigo');
    }

    public function test_automatico_so_unifica_sem_conflito(): void
    {
        $a1 = $this->cliente(['nome' => 'Ana Souza', 'documento' => '11111111111', 'telefone' => '61911110000']);
        $a2 = $this->cliente(['nome' => 'ANA SOUZA', 'documento' => '11111111111', 'telefone' => '61922220000']); // mesmo CPF: A
        $b1 = $this->cliente(['nome' => 'Beto Lima', 'telefone' => '61933330000']);
        $b2 = $this->cliente(['nome' => 'beto lima']); // sem conflito: B
        $c1 = $this->cliente(['nome' => 'Carlos Dias', 'telefone' => '61944440000']);
        $c2 = $this->cliente(['nome' => 'Carlos Dias', 'telefone' => '61955550000']); // telefones diferentes: C
        DB::table('legado_registros')->insert(['origem' => 'sga', 'tabela' => 'TBL_OS', 'linha' => 1, 'cliente_id' => $a2->id, 'dados' => '{}']);

        $this->artisan('oficina:unificar-duplicados')->assertSuccessful()->expectsOutputToContain('SIMULAÇÃO');
        $this->assertSame(6, Cliente::ativos()->count());
        $this->artisan('oficina:unificar-duplicados', ['--executar' => true])->assertSuccessful();
        $this->assertSame($a2->id, $a1->fresh()->mesclado_em_id, 'principal = quem tem mais histórico');
        $this->assertSame('61911110000', $a2->fresh()->legado['_unificados'][0]['cadastro']['telefone'], 'telefone diferente guardado');
        $this->assertSame($b1->id, $b2->fresh()->mesclado_em_id);
        $this->assertNull($c1->fresh()->mesclado_em_id);
        $this->assertNull($c2->fresh()->mesclado_em_id, 'conflito não é unificado');
        $this->artisan('oficina:unificar-duplicados', ['--executar' => true])->assertSuccessful(); // repetir: nada a fazer
        $this->assertSame(4, Cliente::ativos()->count());
    }
}
