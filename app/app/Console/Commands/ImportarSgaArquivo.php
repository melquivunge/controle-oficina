<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\Veiculo;
use App\Services\Auditoria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Arquivo completo do SGA (09/11/2026): TODAS as tabelas exportadas (CSV) vão para legado_registros, isoladas da operação.
 * Liga OS (e serviços, peças, itens de nota, faturas, boletos, orçamentos via CHOS) ao cliente e ao carro da época;
 * notas, vendas e recibos via CHCLIENTE. Colunas de senha são removidas. Rode depois de oficina:importar-sga.
 * Sem --executar só mostra o relatório. Repetível (origem+tabela+linha únicos).
 */
class ImportarSgaArquivo extends Command
{
    protected $signature = 'oficina:importar-sga-arquivo {pasta} {--executar : grava de verdade}';

    protected $description = 'Importa o arquivo completo do sistema antigo (SGA) ligado aos clientes. Sem --executar só simula.';

    public function handle(): int
    {
        $pasta = rtrim((string) $this->argument('pasta'), '/');
        $arquivos = glob("{$pasta}/*.csv") ?: [];
        if (! $arquivos || ! is_readable("{$pasta}/TBL_OS.csv")) {
            $this->error("Nenhum CSV do SGA em {$pasta} (TBL_OS.csv é obrigatório).");

            return self::FAILURE;
        }
        $clientes = Cliente::where('legado_origem', 'sga')->pluck('id', 'legado_codigo');
        $veiculos = Veiculo::where('legado_origem', 'sga')->pluck('id', 'legado_codigo');
        if ($clientes->isEmpty()) {
            $this->error('Clientes do SGA ainda não importados: rode oficina:importar-sga antes.');

            return self::FAILURE;
        }
        $os = [];
        foreach ($this->linhas("{$pasta}/TBL_OS.csv") as $o) {
            $os[$o['CODIGO']] = [$o['CHCLIENTE'], $o['CHVEICULO']];
        }
        $nf = [];
        foreach (is_readable("{$pasta}/TBL_NF_2.csv") ? $this->linhas("{$pasta}/TBL_NF_2.csv") : [] as $n) {
            $nf[$n['CODIGO']] = $n['CHCLIENTE'];
        }
        $nomes = ['TBL_SERVICOP' => ['NOME', '_servico', 'CHSERVICOP'], 'TBL_PRODUTO' => ['DESCRICAO', '_produto', 'CHPRODUTO']];
        $catalogo = [];
        foreach ($nomes as $t => [$col]) {
            foreach (is_readable("{$pasta}/{$t}.csv") ? $this->linhas("{$pasta}/{$t}.csv") : [] as $l) {
                $catalogo[$t][$l['CODIGO']] = $l[$col] ?? null;
            }
        }

        $executar = (bool) $this->option('executar');
        $tot = ['tabelas' => 0, 'linhas' => 0, 'ligadas_cliente' => 0, 'os' => 0, 'os_ligadas' => 0, 'senhas_removidas' => 0, 'novas' => 0];
        foreach ($arquivos as $arq) {
            $tabela = basename($arq, '.csv');
            if (str_starts_with($tabela, '_')) {
                continue;
            }
            $tot['tabelas']++;
            $lote = [];
            $n = 0;
            foreach ($this->linhas($arq) as $l) {
                $n++;
                foreach ($l as $c => $v) {
                    if (str_contains($v, "\0")) {
                        $l[$c] = $v = str_replace("\0", '␀', $v); // PostgreSQL não guarda o caractere nulo: vira o símbolo ␀ (sem perder o resto)
                    }
                    if (preg_match('/^SENHA/i', $c) && $v !== '') {
                        $l[$c] = '[removido]';
                        $tot['senhas_removidas']++;
                    }
                }
                foreach ($nomes as $t => [, $chave, $fk]) {
                    if (($l[$fk] ?? '') !== '' && isset($catalogo[$t][$l[$fk]])) {
                        $l[$chave] = $catalogo[$t][$l[$fk]];
                    }
                }
                [$cli, $vei, $grupo] = $this->ligar($tabela, $l, $os, $nf);
                $clienteId = $cli !== null ? ($clientes[(int) $cli] ?? null) : null;
                $tot['ligadas_cliente'] += $clienteId ? 1 : 0;
                if ($tabela === 'TBL_OS') {
                    $tot['os']++;
                    $tot['os_ligadas'] += $clienteId ? 1 : 0;
                }
                $lote[] = ['origem' => 'sga', 'tabela' => $tabela, 'linha' => $n, 'codigo' => isset($l['CODIGO']) && $l['CODIGO'] !== '' ? mb_substr($l['CODIGO'], 0, 40) : null,
                    'grupo' => $grupo, 'cliente_id' => $clienteId, 'veiculo_id' => $vei !== null ? ($veiculos[(int) $vei] ?? null) : null,
                    'dados' => json_encode(array_filter($l, fn ($v) => $v !== ''), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
                if (count($lote) === 500) {
                    $tot['novas'] += $this->gravar($lote, $executar);
                    $lote = [];
                }
            }
            $tot['novas'] += $this->gravar($lote, $executar);
            $tot['linhas'] += $n;
        }
        if ($executar) {
            Auditoria::registrar('importacao_sga_arquivo', depois: $tot);
        }
        $this->line(($executar ? 'IMPORTADO' : 'SIMULAÇÃO (nada gravado; use --executar)').':');
        $this->table(['Item', 'Quantidade'], [['Tabelas', $tot['tabelas']], ['Linhas', $tot['linhas']], ['Linhas ligadas a um cliente', $tot['ligadas_cliente']],
            ['OS antigas', $tot['os']], ['OS ligadas ao cliente', $tot['os_ligadas']], ['Senhas removidas', $tot['senhas_removidas']],
            [$executar ? 'Linhas novas gravadas' : 'Linhas que seriam gravadas', $tot['novas']]]);

        return self::SUCCESS;
    }

    /** @return array{0:?string,1:?string,2:?string} [código cliente SGA, código veículo SGA, grupo] */
    private function ligar(string $tabela, array $l, array $os, array $nf): array
    {
        if ($tabela === 'TBL_OS') {
            return [$l['CHCLIENTE'] ?: null, $l['CHVEICULO'] ?: null, 'OS:'.$l['CODIGO']];
        }
        if ($tabela === 'TBL_CLIENTE') {
            return [$l['CODIGO'], null, null];
        }
        if ($tabela === 'TBL_VEICULO') {
            return [$l['CHCLIENTE'] ?: null, $l['CODIGO'], null];
        }
        if (($l['CHOS'] ?? '') !== '' && isset($os[$l['CHOS']])) {
            [$c, $v] = $os[$l['CHOS']];

            return [$c ?: null, $v ?: null, 'OS:'.$l['CHOS']];
        }
        if (($l['CHCLIENTE'] ?? '') !== '' && ! in_array($tabela, ['TBL_CATFORN', 'TBL_CATFUNC'], true)) {
            return [$l['CHCLIENTE'], null, null];
        }
        if (($l['CHNF_2'] ?? '') !== '' && isset($nf[$l['CHNF_2']])) {
            return [$nf[$l['CHNF_2']] ?: null, null, 'NF:'.$l['CHNF_2']];
        }

        return [null, null, null];
    }

    private function gravar(array $lote, bool $executar): int
    {
        if (! $lote) {
            return 0;
        }
        if (! $executar) {
            return count($lote) - DB::table('legado_registros')->where('origem', 'sga')->where('tabela', $lote[0]['tabela'])
                ->whereIn('linha', array_column($lote, 'linha'))->count();
        }

        return DB::table('legado_registros')->insertOrIgnore($lote);
    }

    /** @return \Generator<array<string,string>> */
    private function linhas(string $arquivo): \Generator
    {
        $f = fopen($arquivo, 'r');
        $cab = fgetcsv($f, escape: '');
        while (($l = fgetcsv($f, escape: '')) !== false) {
            if ($l !== [null]) {
                yield array_combine($cab, array_pad($l, count($cab), ''));
            }
        }
        fclose($f);
    }
}
