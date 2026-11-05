<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Unidade;
use App\Services\Entradas;
use App\Services\Financeiro;
use App\Services\Orcamentos;
use App\Services\Ordens;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

/** Exportação do painel em Excel (31/10/2026): três abas com TOTAL, valores numéricos, formatação e mesma autorização do CSV. */
class PainelExcelTest extends TestCase
{
    /** @return array<string, list<list<mixed>>> aba => linhas */
    private function ler(string $arquivo): array
    {
        $r = new Reader;
        $r->open($arquivo);
        $abas = [];
        foreach ($r->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $abas[$sheet->getName()][] = $row->toArray();
            }
        }
        $r->close();

        return $abas;
    }

    public function test_excel_com_totais_formatacao_e_autorizacao(): void
    {
        $a = Unidade::factory()->create(['nome' => '=Oficina A']);
        $c = Unidade::factory()->create(['nome' => 'Oficina C']);
        $b = Unidade::factory()->create(['nome' => 'Oficina B']);
        $dono = $this->usuario(Papel::Proprietario);
        $rec = $this->usuario(Papel::Recepcao, $a);
        $ger = $this->usuario(Papel::Gerente, $a);
        foreach ([[$a, 10000, 'QWE4R56'], [$c, 2550, 'ABC1D23']] as [$u, $valor, $placa]) {
            $r = $u->is($a) ? $rec : $this->usuario(Papel::Recepcao, $u);
            $g = $u->is($a) ? $ger : $this->usuario(Papel::Gerente, $u);
            $o = app(Entradas::class)->registrar($r, ['unidade_id' => $u->id, 'nome' => 'Mariana', 'telefone' => '61991234567', 'placa' => $placa, 'motivo' => 'x']);
            app(Ordens::class)->iniciarDiagnostico($o, $this->usuario(Papel::Mecanico, $u));
            $v = app(Orcamentos::class)->salvarRascunho($o, $g, [['descricao' => 'Serviço', 'quantidade' => '1', 'preco' => '250']]);
            app(Orcamentos::class)->enviar($v, $g);
            app(Orcamentos::class)->decidir($v->id, $o->id, 'aprovado', 'link_cliente');
            app(Financeiro::class)->registrarManual($o->fresh(), $r, 'dinheiro', $valor);
        }

        // Botão aparece para quem vê o financeiro; recepção não exporta; unidade alheia barrada.
        $this->entrarComo($ger)->get('/?periodo=7')->assertOk()->assertSee('Exportar Excel')->assertSee('formato=xlsx', false);
        $this->entrarComo($rec)->get('/')->assertDontSee('Exportar Excel');
        $this->entrarComo($rec)->get('/painel/exportar?formato=xlsx')->assertForbidden();
        $this->entrarComo($ger)->get("/painel/exportar?formato=xlsx&unidade={$b->id}")->assertForbidden();

        $resp = $this->entrarComo($dono)->get('/painel/exportar?periodo=7&formato=xlsx')->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $resp->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', $resp->headers->get('Content-Disposition'));
        $arquivo = $resp->baseResponse->getFile()->getPathname();
        $abas = $this->ler($arquivo);
        $this->assertSame(['Resumo por unidade', 'Por dia', 'Dia da semana'], array_keys($abas));

        $resumo = $abas['Resumo por unidade'];
        $this->assertStringContainsString('Resumo por unidade', $resumo[0][0]);
        $this->assertStringContainsString('Unidade: Todas', $resumo[1][0]);
        $cab = $resumo[2]; // o leitor pula a linha vazia (linha 3 do arquivo)
        $this->assertSame(['Unidade', 'Entradas', 'Entregas', 'Orçado', 'Aprovado', 'Recebido'], array_slice($cab, 0, 6));
        $col = array_flip($cab);
        $porNome = collect(array_slice($resumo, 3))->keyBy(0);
        // Nome com "=" fica texto; valores são números e o TOTAL soma as unidades.
        $this->assertSame('=Oficina A', $porNome['=Oficina A'][0]);
        $this->assertIsNotString($porNome['=Oficina A'][$col['Recebido']]);
        $this->assertEqualsWithDelta(100.0, $porNome['=Oficina A'][$col['Recebido']], 0.001);
        $this->assertEqualsWithDelta(125.5, $porNome['TOTAL'][$col['Recebido']], 0.001);
        $this->assertEqualsWithDelta(500.0, $porNome['TOTAL'][$col['Aprovado']], 0.001);
        $this->assertSame(2, (int) $porNome['TOTAL'][$col['Entradas']]);
        $this->assertSame('TOTAL', $porNome->keys()->last());

        $dia = collect(array_slice($abas['Por dia'], 3));
        $this->assertSame(8, $dia->count(), '7 dias + TOTAL');
        $this->assertEqualsWithDelta(125.5, $dia->last()[4], 0.001);
        $semana = collect(array_slice($abas['Dia da semana'], 3));
        $this->assertSame(8, $semana->count(), '7 dias da semana + TOTAL');
        $this->assertEqualsWithDelta(125.5, $semana->last()[1], 0.001);

        // Nenhuma fórmula no arquivo; cabeçalho navy e formato de moeda presentes.
        $zip = new ZipArchive;
        $zip->open($arquivo);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with($zip->getNameIndex($i), 'xl/worksheets/')) {
                $this->assertStringNotContainsString('<f>', $zip->getFromIndex($i));
            }
        }
        $estilos = $zip->getFromName('xl/styles.xml');
        $this->assertStringContainsString('0F1B2D', $estilos);
        $this->assertStringContainsString('R$', $estilos);
        $zip->close();

        // Gerente só vê a própria unidade.
        $g = $this->ler($this->entrarComo($ger)->get('/painel/exportar?periodo=7&formato=xlsx')->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertSame(['=Oficina A', 'TOTAL'], array_column(array_slice($g['Resumo por unidade'], 3), 0));
    }
}
