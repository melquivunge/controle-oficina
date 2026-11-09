<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\Unidade;
use App\Services\Entradas;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pix recebido no app do banco e cartão com comprovante (09/11/2026). */
class PagamentoComprovanteTest extends TestCase
{
    public function test_pix_no_app_sem_arquivo_exige_so_a_conferencia_do_extrato(): void
    {
        Storage::fake('local');
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $ger = $this->usuario(Papel::Gerente, $u);
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'Mariana Costa', 'telefone' => '61991234567', 'placa' => 'ABC1D23', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->usuario(Papel::Mecanico, $u));
        app(Orcamentos::class)->aprovarDireto(app(Orcamentos::class)->salvarRascunho($o, $ger, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '300']]), $ger);
        $url = route('pagamentos.agora', $o);

        $this->entrarComo($rec)->post($url, ['forma_agora' => 'pix_manual', 'valor_agora' => '100,00'])->assertSessionHasErrors('comprovante');
        $this->assertSame(0, \App\Models\Pagamento::where('ordem_id', $o->id)->count());
        $this->post($url, ['forma_agora' => 'pix_manual', 'valor_agora' => '100,00', 'conferido_extrato' => '1'])->assertSessionHasNoErrors();
        $p = \App\Models\Pagamento::where('ordem_id', $o->id)->sole();
        $this->assertSame(['pix_manual', null], [$p->meio, $p->comprovante_caminho]);
    }

    public function test_pix_no_app_exige_conferencia_e_cartao_aceita_recibo(): void
    {
        Storage::fake('local');
        $u = Unidade::factory()->create();
        $rec = $this->usuario(Papel::Recepcao, $u);
        $ger = $this->usuario(Papel::Gerente, $u);
        $o = app(Entradas::class)->registrar($rec, ['unidade_id' => $u->id, 'nome' => 'Mariana Costa', 'telefone' => '61991234567', 'placa' => 'ABC1D23', 'motivo' => 'x']);
        app(Ordens::class)->iniciarDiagnostico($o, $this->usuario(Papel::Mecanico, $u));
        app(Orcamentos::class)->aprovarDireto(app(Orcamentos::class)->salvarRascunho($o, $ger, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '300']]), $ger);
        $url = route('pagamentos.agora', $o);
        $this->entrarComo($rec)->get(route('ordens.show', $o).'?aba=pagamentos')->assertSee('Pix já recebido no app do banco')->assertSee('Foto ou PDF do comprovante');

        // Sem conferir o extrato ou arquivo inválido: recusado, nada gravado.
        $this->post($url, ['forma_agora' => 'pix_manual', 'valor_agora' => '100,00', 'comprovante' => UploadedFile::fake()->create('pix.jpg', 40, 'image/jpeg')])->assertSessionHasErrors('comprovante');
        $this->post($url, ['forma_agora' => 'pix_manual', 'valor_agora' => '100,00', 'conferido_extrato' => '1', 'comprovante' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('comprovante');
        $this->assertSame(0, Pagamento::count());

        $this->post($url, ['forma_agora' => 'pix_manual', 'valor_agora' => '100,00', 'conferido_extrato' => '1', 'comprovante' => UploadedFile::fake()->create('pix.jpg', 40, 'image/jpeg')])->assertSessionHasNoErrors();
        $pix = Pagamento::sole();
        $this->assertSame(['pix_manual', 'registrado', 10000], [$pix->meio, $pix->status, $pix->valor_centavos]);
        Storage::disk('local')->assertExists($pix->comprovante_caminho);

        $this->post($url, ['forma_agora' => 'cartao_externo', 'valor_agora' => '200,00', 'comprovante' => UploadedFile::fake()->create('recibo.pdf', 50, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame(0, app(\App\Services\Financeiro::class)->resumo(Ordem::find($o->id))['saldo'], 'quitado: 100 Pix + 200 cartão');

        // Comprovante: só quem recebe pagamentos na unidade.
        $this->get(route('ordens.show', $o).'?aba=pagamentos')->assertSee('Ver comprovante');
        $this->get(route('pagamentos.comprovante', [$o, $pix]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->entrarComo($this->usuario(Papel::Mecanico, $u))->get(route('pagamentos.comprovante', [$o, $pix]))->assertForbidden();
        $this->entrarComo($this->usuario(Papel::Recepcao, Unidade::factory()->create()))->get(route('pagamentos.comprovante', [$o, $pix]))->assertForbidden();
    }
}
