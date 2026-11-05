<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\AuditEvent;
use App\Models\MessageDelivery;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Entradas;
use App\Services\Orcamentos;
use App\Services\Ordens;
use Tests\TestCase;

/** Aviso de serviço ao colaborador (opcional): sem preço nem contato do cliente, só com telefone + preferência, SIMULADO. */
class AvisoColaboradorTest extends TestCase
{
    private const TEL_CLIENTE = '61991234567';
    private const TEL_MECANICO = '61988887777';

    private Unidade $unidade;
    private User $gerente;
    private User $recepcao;
    private User $mecanico;
    private Ordem $ordem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unidade = Unidade::factory()->create();
        $this->gerente = $this->usuario(Papel::Gerente, $this->unidade);
        $this->recepcao = $this->usuario(Papel::Recepcao, $this->unidade);
        $this->mecanico = $this->usuario(Papel::Mecanico, $this->unidade);
        $this->mecanico->forceFill(['telefone' => self::TEL_MECANICO, 'receber_avisos_whatsapp' => true])->save();
        $this->ordem = app(Entradas::class)->registrar($this->recepcao, ['unidade_id' => $this->unidade->id, 'nome' => 'Mariana Costa', 'telefone' => self::TEL_CLIENTE,
            'placa' => 'RDA1B23', 'modelo' => 'Onix', 'motivo' => 'Jogo de rodas — reforma geral aro 17. Cliente pediu ligar no 61 99123-4567, paga R$ 900']);
    }

    private function avisos()
    {
        return MessageDelivery::where('tipo', 'aviso_colaborador')->orderBy('id')->get();
    }

    private function assertSemPrecoNemContato(string $texto): void
    {
        foreach (['R$', 'r$', self::TEL_CLIENTE, '99123-4567', '991234567', '900', '250,00', 'Mariana'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $texto, "“{$proibido}” vazou no aviso ao colaborador");
        }
    }

    public function test_atribuicao_envia_aviso_simulado_sem_preco_nem_contato_do_cliente_e_audita(): void
    {
        app(Ordens::class)->atribuirMecanico($this->ordem, $this->mecanico, $this->gerente);
        $m = $this->avisos()->sole();
        $this->assertSame(self::TEL_MECANICO, $m->destino);
        $this->assertSame('simulado', $m->canal, 'modo demo/simulado: nada sai do sistema');
        $this->assertSame('enviado', $m->status);
        $this->assertStringStartsWith('SIM-MSG-', $m->id_provedor);
        $this->assertNull($m->getRawOriginal('segredo_link'), 'aviso nunca leva link do portal do cliente');
        $this->assertStringContainsString($this->ordem->numero, $m->conteudo);
        $this->assertStringContainsString('placa RDA1B23', $m->conteudo);
        $this->assertStringContainsString('Jogo de rodas', $m->conteudo);
        $this->assertStringContainsString('Entrega prevista: a definir', $m->conteudo);
        $this->assertSemPrecoNemContato($m->conteudo);
        $ev = AuditEvent::where('acao', 'aviso_colaborador_enfileirado')->sole();
        $this->assertEquals(['mensagem_id' => $m->id, 'colaborador_id' => $this->mecanico->id, 'motivo' => 'atribuicao', 'canal' => 'simulado'], $ev->depois);
        $this->assertSame($this->ordem->id, $ev->entidade_id);
        $this->assertStringNotContainsString(self::TEL_MECANICO, json_encode($ev->depois));
    }

    public function test_autorizacao_com_mecanico_envia_aviso_com_itens_sem_valores(): void
    {
        $this->actingAs($this->mecanico); // mecânico assumindo da fila não recebe aviso de si mesmo
        app(Ordens::class)->iniciarDiagnostico($this->ordem, $this->mecanico);
        $this->assertCount(0, $this->avisos());
        app(Ordens::class)->registrarDiagnostico($this->ordem, 'Empeno leve.', $this->mecanico);
        $s = app(Orcamentos::class);
        $v = $s->salvarRascunho($this->ordem->fresh(), $this->gerente, [
            ['descricao' => 'Reforma geral de roda aro 17', 'quantidade' => '4', 'preco' => '250,00'],
            ['descricao' => 'Pintura especial R$ 300 combinado com cliente 61991234567', 'quantidade' => '1', 'preco' => '300,00'],
        ]);
        $s->enviar($v, $this->gerente);
        $s->decidirPresencial($v->fresh(), $this->recepcao, 'aprovado', 'Cliente aprovou');
        $m = $this->avisos()->sole();
        $this->assertStringStartsWith('Serviço autorizado', $m->conteudo);
        $this->assertStringContainsString('4 × Reforma geral de roda aro 17', $m->conteudo);
        $this->assertSemPrecoNemContato($m->conteudo);
        $this->assertSame('autorizado', AuditEvent::where('acao', 'aviso_colaborador_enfileirado')->sole()->depois['motivo']);
    }

    public function test_sem_preferencia_ou_sem_telefone_nao_envia(): void
    {
        $this->mecanico->forceFill(['receber_avisos_whatsapp' => false])->save();
        app(Ordens::class)->atribuirMecanico($this->ordem, $this->mecanico, $this->gerente);
        $outro = $this->usuario(Papel::Mecanico, $this->unidade);
        $outro->forceFill(['telefone' => null, 'receber_avisos_whatsapp' => true])->save();
        app(Ordens::class)->atribuirMecanico($this->ordem->fresh(), $outro, $this->gerente);
        $this->assertCount(0, $this->avisos());
        $this->assertFalse(AuditEvent::where('acao', 'aviso_colaborador_enfileirado')->exists());
        $this->assertSame($outro->id, $this->ordem->fresh()->mecanico_id, 'atribuição segue normalmente');
        // Padrão do banco: desligado.
        $this->assertFalse($this->usuario(Papel::Mecanico, $this->unidade)->fresh()->receber_avisos_whatsapp);
    }

    public function test_falha_no_aviso_vira_pendencia_e_reenvio_nao_gera_link_do_cliente(): void
    {
        $this->mecanico->forceFill(['telefone' => '61988880000'])->save(); // simulador: final 0000 = provedor indisponível
        try {
            app(Ordens::class)->atribuirMecanico($this->ordem, $this->mecanico, $this->gerente);
        } catch (\Throwable) {
            // fila síncrona nos testes propaga a falha do provedor simulado; a atribuição já foi gravada
        }
        $this->assertSame($this->mecanico->id, $this->ordem->fresh()->mecanico_id);
        $m = $this->avisos()->sole();
        $m->update(['status' => 'falhou', 'destino' => self::TEL_MECANICO]);
        $links = \App\Models\LinkCliente::where('ordem_id', $this->ordem->id)->count(); // a entrada já gera o link do cliente
        $this->entrarComo($this->gerente)->post("/pendencias/mensagens/{$m->id}/reenviar")->assertRedirect();
        $this->assertNull($m->fresh()->getRawOriginal('segredo_link'));
        $this->assertSame($links, \App\Models\LinkCliente::where('ordem_id', $this->ordem->id)->count(), 'reenvio não cria link do portal');
        $this->assertSame('enviado', $m->fresh()->status);
    }

    public function test_colaborador_liga_e_desliga_preferencia_em_conta_exigindo_telefone(): void
    {
        $u = $this->usuario(Papel::Mecanico, $this->unidade);
        $this->entrarComo($u)->get('/conta')->assertOk()->assertSee('Receber avisos de serviço pelo WhatsApp');
        $this->entrarComo($u)->put('/conta/perfil', ['name' => $u->name, 'receber_avisos_whatsapp' => '1'])->assertSessionHasErrors('receber_avisos_whatsapp');
        $this->assertFalse($u->fresh()->receber_avisos_whatsapp);
        $this->entrarComo($u)->put('/conta/perfil', ['name' => $u->name, 'telefone' => '(61) 98888-7777', 'receber_avisos_whatsapp' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($u->fresh()->receber_avisos_whatsapp);
        $this->assertTrue(AuditEvent::where('acao', 'perfil_alterado')->get()->contains(fn ($e) => ($e->depois['receber_avisos_whatsapp'] ?? null) === true));
        $this->entrarComo($u)->put('/conta/perfil', ['name' => $u->name, 'telefone' => '61988887777'])->assertSessionHasNoErrors();
        $this->assertFalse($u->fresh()->receber_avisos_whatsapp);
    }
}
