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
        $this->assertSame(['Resumo por unidade', 'Por dia', 'Dia da semana', 'Ordens', 'Recebimentos'], array_keys($abas));

        // 06/11/2026: uma linha por OS e por recebimento, com TOTAL; loja em texto (nome com "=" não vira fórmula).
        $ordens = array_slice($abas['Ordens'], 2);
        $this->assertSame(['OS', 'Loja', 'Entrada', 'Cliente', 'Veículo / item', 'Situação', 'Aprovado', 'Recebido', 'Saldo'], $ordens[0]);
        $this->assertSame(3, count($ordens) - 1, '2 ordens + TOTAL');
        $this->assertSame(['=Oficina A', 'Mariana', 'Autorizado'], [$ordens[1][1], $ordens[1][3], $ordens[1][5]]);
        $this->assertEqualsWithDelta(250.0, $ordens[1][6], 0.001);
        $this->assertEqualsWithDelta(150.0, $ordens[1][8], 0.001); // saldo 250 - 100
        $this->assertSame('TOTAL', $ordens[3][0]);
        $this->assertEqualsWithDelta([500.0, 125.5, 374.5], array_slice($ordens[3], 6, 3), 0.001);
        $receb = array_slice($abas['Recebimentos'], 2);
        $this->assertSame(['Data', 'Loja', 'OS', 'Cliente', 'Forma', 'Valor'], $receb[0]);
        $this->assertSame(['Dinheiro', 'Dinheiro'], [$receb[1][4], $receb[2][4]]);
        $this->assertEqualsWithDelta(125.5, $receb[3][5], 0.001);

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
        $this->assertSame(['=Oficina A'], array_values(array_unique(array_column(array_slice($g['Ordens'], 3, -1), 1))));
        $this->assertCount(1 + 1, array_slice($g['Recebimentos'], 3), 'só o recebimento da própria loja + TOTAL');

        // Uma loja escolhida (dono): só ela nas listas. Painel simples oferece o download.
        $so = $this->ler($this->entrarComo($dono)->get("/painel/exportar?periodo=7&formato=xlsx&unidade={$c->id}")->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertSame(['Oficina C'], array_values(array_unique(array_column(array_slice($so['Ordens'], 3, -1), 1))));
        $dono->forceFill(['painel_simples' => true])->save();
        $this->entrarComo($dono)->get('/')->assertOk()->assertSee('Baixar planilha Excel')->assertSee('Todas as lojas juntas');
    }
}
