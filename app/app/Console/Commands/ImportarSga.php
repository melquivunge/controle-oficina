<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\Veiculo;
use App\Services\Auditoria;
use App\Support\Normaliza;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa clientes e veículos do sistema antigo (SGA) a partir dos CSV da extração completa (09/11/2026).
 * Sem --executar: só simula e mostra o relatório. Nada é perdido: o registro original vai inteiro em `legado`.
 * Repetível: quem já foi importado (legado_origem='sga' + código) é pulado. Placa já existente no sistema não é tomada
 * de outro cliente: fica registrada no legado do cliente e no relatório.
 */
class ImportarSga extends Command
{
    protected $signature = 'oficina:importar-sga {pasta : pasta com TBL_CLIENTE.csv, TBL_VEICULO.csv e tabelas auxiliares} {--executar : grava de verdade}';

    protected $description = 'Importa clientes e veículos do sistema antigo (SGA). Sem --executar só simula.';

    public function handle(): int
    {
        $pasta = rtrim((string) $this->argument('pasta'), '/');
        foreach (['TBL_CLIENTE', 'TBL_VEICULO', 'TBL_MUNICIPIO', 'TBL_COR', 'TBL_FABRICANTE_VEICULO', 'TBL_MODELO_VEICULO'] as $t) {
            if (! is_readable("{$pasta}/{$t}.csv")) {
                $this->error("Arquivo ausente: {$pasta}/{$t}.csv");

                return self::FAILURE;
            }
        }
        $municipios = collect($this->csv("{$pasta}/TBL_MUNICIPIO.csv"))->keyBy('CODIGO');
        $cores = collect($this->csv("{$pasta}/TBL_COR.csv"))->pluck('DESCRICAO', 'CODIGO');
        $fabricantes = collect($this->csv("{$pasta}/TBL_FABRICANTE_VEICULO.csv"))->pluck('DESCRICAO', 'CODIGO');
        $modelos = collect($this->csv("{$pasta}/TBL_MODELO_VEICULO.csv"))->pluck('DESCRICAO', 'CODIGO');
        $clientes = $this->csv("{$pasta}/TBL_CLIENTE.csv");
        $veiculosPorCliente = collect($this->csv("{$pasta}/TBL_VEICULO.csv"))->groupBy('CHCLIENTE');

        $r = ['clientes' => 0, 'ja_importados' => 0, 'sem_telefone' => 0, 'com_documento' => 0, 'veiculos' => 0, 'veiculos_ja_importados' => 0,
            'placas_fora_do_padrao' => 0, 'placas_em_conflito' => [], 'placas_invalidas' => []];
        $executar = (bool) $this->option('executar');

        $rodar = function () use ($clientes, $veiculosPorCliente, $municipios, $cores, $fabricantes, $modelos, &$r) {
            foreach ($clientes as $c) {
                $codigo = (int) $c['CODIGO'];
                $mun = $municipios[$c['CHMUNICIPIO']] ?? null;
                $legadoCli = array_filter($c, fn ($v) => $v !== '') + array_filter(['_municipio' => $mun['MUNICIPIO'] ?? null, '_uf' => $mun['UF'] ?? null]);
                $cliente = Cliente::where('legado_origem', 'sga')->where('legado_codigo', $codigo)->first();
                if ($cliente) {
                    $r['ja_importados']++;
                } else {
                    $telefone = $this->telefone($c);
                    $documento = preg_replace('/\D/', '', $c['CPF'] ?: $c['CGC']);
                    $r['clientes']++;
                    $r['sem_telefone'] += $telefone === '' ? 1 : 0;
                    $r['com_documento'] += in_array(strlen($documento), [11, 14], true) ? 1 : 0;
                    $cliente = new Cliente([
                        'nome' => mb_substr(trim($c['NOME']) ?: trim($c['NOME_FANTASIA']) ?: "Cliente SGA {$codigo}", 0, 150),
                        'telefone' => $telefone,
                        'email' => filter_var(trim(explode(';', str_replace(',', ';', $c['EMAIL']))[0]), FILTER_VALIDATE_EMAIL) ?: null,
                        'observacoes' => trim($c['OBS']) ?: null,
                        'cep' => strlen($cep = preg_replace('/\D/', '', $c['CEP'])) === 8 ? $cep : null,
                        'logradouro' => mb_substr(trim($c['ENDERECO']), 0, 150) ?: null,
                        'numero' => mb_substr(trim($c['NUMERO']), 0, 20) ?: null,
                        'bairro' => mb_substr(trim($c['BAIRRO']), 0, 80) ?: null,
                        'cidade' => mb_substr((string) ($mun['MUNICIPIO'] ?? ''), 0, 80) ?: null,
                        'uf' => strlen((string) ($mun['UF'] ?? '')) === 2 ? $mun['UF'] : null,
                    ]);
                    $cliente->forceFill(['documento' => in_array(strlen($documento), [11, 14], true) ? $documento : null,
                        'legado_origem' => 'sga', 'legado_codigo' => $codigo, 'legado' => $legadoCli]);
                    if ($c['DATACAD'] !== '') {
                        $cliente->created_at = CarbonImmutable::parse($c['DATACAD']);
                    }
                    $cliente->save();
                }
                foreach ($veiculosPorCliente[$c['CODIGO']] ?? [] as $v) {
                    if (Veiculo::where('legado_origem', 'sga')->where('legado_codigo', (int) $v['CODIGO'])->exists()) {
                        $r['veiculos_ja_importados']++;

                        continue;
                    }
                    $placa = Normaliza::placa($v['PLACA']);
                    $legadoVei = array_filter($v, fn ($x) => $x !== '') + array_filter(['_fabricante' => $fabricantes[$v['CHFABRICANTE']] ?? null,
                        '_modelo' => $modelos[$v['CHMODELO']] ?? null, '_cor' => $cores[$v['CHCOR']] ?? null]);
                    if ($placa === '' || strlen($placa) > 7) {
                        $r['placas_invalidas'][] = $v['PLACA'];
                        $this->guardarNoCliente($cliente, $legadoVei);

                        continue;
                    }
                    if (Veiculo::where('placa', $placa)->exists()) {
                        $r['placas_em_conflito'][] = $placa;
                        $this->guardarNoCliente($cliente, $legadoVei);

                        continue;
                    }
                    $r['placas_fora_do_padrao'] += preg_match(Normaliza::REGEX_PLACA, $placa) ? 0 : 1;
                    $veiculo = new Veiculo(['placa' => $placa,
                        'marca' => mb_substr(trim((string) ($fabricantes[$v['CHFABRICANTE']] ?? $v['FABRICANTE_OLD'])), 0, 40) ?: null,
                        'modelo' => mb_substr(trim((string) ($modelos[$v['CHMODELO']] ?? $v['MODELO_OLD'])), 0, 80) ?: null,
                        'ano' => $this->ano($v['ANOMOD']),
                        'cor' => mb_substr(trim((string) ($cores[$v['CHCOR']] ?? $v['COR_OLD'])), 0, 30) ?: null]);
                    $veiculo->cliente_id = $cliente->id;
                    $veiculo->forceFill(['legado_origem' => 'sga', 'legado_codigo' => (int) $v['CODIGO'], 'legado' => $legadoVei]);
                    if ($v['DATA_CAD'] !== '') {
                        $veiculo->created_at = CarbonImmutable::parse($v['DATA_CAD']);
                    }
                    $veiculo->save();
                    $r['veiculos']++;
                }
            }
        };

        DB::beginTransaction();
        try {
            $rodar();
            if ($executar) {
                Auditoria::registrar('importacao_sga', depois: array_merge($r, ['placas_em_conflito' => count($r['placas_em_conflito']), 'placas_invalidas' => count($r['placas_invalidas'])]));
                DB::commit();
            } else {
                DB::rollBack(); // simulação: tudo é desfeito
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->line(($executar ? 'IMPORTADO' : 'SIMULAÇÃO (nada gravado; use --executar)').':');
        $this->table(['Item', 'Quantidade'], [
            ['Clientes novos', $r['clientes']], ['Clientes já importados (pulados)', $r['ja_importados']], ['… sem telefone', $r['sem_telefone']],
            ['… com CPF/CNPJ', $r['com_documento']], ['Veículos novos', $r['veiculos']], ['Veículos já importados (pulados)', $r['veiculos_ja_importados']],
            ['… placa fora do padrão (importada assim mesmo)', $r['placas_fora_do_padrao']],
            ['Placas já existentes em outro cliente (guardadas no legado do cliente)', count($r['placas_em_conflito'])],
            ['Placas vazias/longas (guardadas no legado do cliente)', count($r['placas_invalidas'])],
        ]);
        if ($r['placas_em_conflito']) {
            $this->line('Conflitos: '.implode(', ', $r['placas_em_conflito']));
        }

        return self::SUCCESS;
    }

    /** Veículo que não pôde virar registro próprio: os dados ficam no legado do cliente (nada se perde). */
    private function guardarNoCliente(Cliente $cliente, array $legadoVei): void
    {
        $l = $cliente->legado ?? [];
        if (collect($l['_veiculos_nao_importados'] ?? [])->contains('CODIGO', $legadoVei['CODIGO'])) {
            return; // repetição da importação: já guardado
        }
        $l['_veiculos_nao_importados'][] = $legadoVei;
        $cliente->forceFill(['legado' => $l])->save();
    }

    /** Primeiro telefone com DDD válido (10 ou 11 dígitos) entre FONE1..3; os demais ficam no legado. */
    private function telefone(array $c): string
    {
        foreach (['FONE1', 'FONE2', 'FONE3'] as $f) {
            $n = Normaliza::telefone($c[$f]);
            if (strlen($n) === 10 || strlen($n) === 11) {
                return $n;
            }
        }

        return '';
    }

    /** "15/16" → 2016 (ano modelo); "16" → 2016; vazio/estranho → null. */
    private function ano(string $s): ?int
    {
        if (! preg_match('/(\d{2,4})\s*$/', trim($s), $m)) {
            return null;
        }
        $a = (int) $m[1];
        $a = $a < 100 ? ($a > (int) now()->format('y') + 1 ? 1900 + $a : 2000 + $a) : $a;

        return $a >= 1950 && $a <= (int) now()->year + 1 ? $a : null;
    }

    /** @return list<array<string,string>> */
    private function csv(string $arquivo): array
    {
        $f = fopen($arquivo, 'r');
        $cab = fgetcsv($f, escape: '');
        $linhas = [];
        while (($l = fgetcsv($f, escape: '')) !== false) {
            if ($l === [null]) {
                continue;
            }
            $linhas[] = array_combine($cab, array_pad($l, count($cab), ''));
        }
        fclose($f);

        return $linhas;
    }
}
