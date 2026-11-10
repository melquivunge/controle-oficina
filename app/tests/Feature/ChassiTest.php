<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Livewire\NovaEntrada;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\Veiculo;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Chassi como dado extra do veículo (pedido da oficina, 10/11/2026). Placa continua obrigatória. */
class ChassiTest extends TestCase
{
    private const VIN = '9BWZZZ377VT004251';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function entrada($recepcao, string $placa, string $chassi = '')
    {
        return Livewire::actingAs($recepcao)->test(NovaEntrada::class)
            ->set('telefone', '61991234567')->set('placa', $placa)->set('nome', 'Saga Jeep')->set('motivo', 'Revisão')->set('chassi', $chassi)
            ->call('salvar');
    }

    public function test_nova_entrada_grava_chassi_valida_formato_e_nao_duplica(): void
    {
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);

        $this->entrada($rec, 'ABC1D23', 'curto')->assertHasErrors(['chassi' => 'regex']);
        $this->entrada($rec, 'ABC1D23', '9BWZZZ377VT00425I')->assertHasErrors(['chassi' => 'regex']); // I não existe em chassi
        $this->entrada($rec, 'ABC1D23', ' 9bw zzz377vt004251 ')->assertHasNoErrors();
        $this->assertSame(self::VIN, Veiculo::where('placa', 'ABC1D23')->value('chassi'));

        $this->entrada($rec, 'XYZ9A87', self::VIN)->assertHasErrors(['chassi' => 'unique']); // mesmo chassi em outra placa
        $this->entrada($rec, 'ABC1D23', self::VIN)->assertHasNoErrors(); // mesmo veículo voltando: ok

        // Veículo sem chassi recebe o chassi informado numa nova entrada; placa sem chassi continua aceita.
        $this->entrada($rec, 'JKL2M34')->assertHasNoErrors();
        $this->assertNull(Veiculo::where('placa', 'JKL2M34')->value('chassi'));
        $this->entrada($rec, 'JKL2M34', '9BD17164G75218904')->assertHasNoErrors();
        $this->assertSame('9BD17164G75218904', Veiculo::where('placa', 'JKL2M34')->value('chassi'));

        // Placa já cadastrada puxa o chassi salvo.
        Livewire::actingAs($rec)->test(NovaEntrada::class)->set('placa', 'ABC1D23')->assertSet('chassi', self::VIN);
    }

    public function test_os_mostra_adiciona_e_altera_chassi_com_autorizacao_e_auditoria(): void
    {
        [$a, $b] = Unidade::factory()->count(2)->create();
        $rec = $this->usuario(Papel::Recepcao, $a);
        $this->entrada($rec, 'ABC1D23')->assertHasNoErrors();
        $o = Ordem::sole();
        $url = route('ordens.chassi', $o);

        $this->entrarComo($rec)->get(route('ordens.show', $o))->assertOk()->assertSee('Adicionar chassi');
        $this->entrarComo($this->usuario(Papel::Mecanico, $a))->put($url, ['chassi' => self::VIN])->assertForbidden();
        $this->entrarComo($this->usuario(Papel::Recepcao, $b))->put($url, ['chassi' => self::VIN])->assertForbidden();
        $this->entrarComo($rec)->put($url, ['chassi' => 'errado'])->assertSessionHasErrors('chassi');

        $this->entrarComo($rec)->put($url, ['chassi' => self::VIN])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(self::VIN, $o->veiculo->fresh()->chassi);
        $this->assertDatabaseHas('audit_events', ['acao' => 'veiculo_chassi_alterado', 'entidade_id' => $o->veiculo_id]);
        $this->entrarComo($rec)->get(route('ordens.show', $o))->assertSee(self::VIN)->assertSee('Alterar chassi');
        $this->entrarComo($rec)->get(route('veiculos.index', ['placa' => 'VT0042']))->assertSee(self::VIN);

        $this->entrarComo($rec)->put($url, ['chassi' => ''])->assertSessionHasNoErrors();
        $this->assertNull($o->veiculo->fresh()->chassi);
    }
}
