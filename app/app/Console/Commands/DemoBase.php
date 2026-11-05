<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Guardas comuns: nunca em produção; somente a conexão demo, distinta do banco principal e com marcador próprio. */
abstract class DemoBase extends Command
{
    protected function guardas(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Comandos de demonstração são bloqueados em produção.');
        }
        $demo = config('database.connections.demo.database');
        $principal = config('database.connections.pgsql.database');
        if (! $demo || $demo === $principal || DB::connection('demo')->getDatabaseName() !== $demo) {
            throw new RuntimeException("Banco de demonstração inválido ou igual ao principal ({$demo}).");
        }
    }

    /** Usa a conexão demo como padrão, fila síncrona (jobs no próprio banco demo) e integrações simuladas. */
    protected function usarDemo(): void
    {
        config(['database.default' => 'demo', 'queue.default' => 'sync', 'oficina.integracoes' => 'simulado',
            'filesystems.disks.local.root' => storage_path('app/private/demo')]);
        DB::setDefaultConnection('demo');
        app()->forgetInstance('filesystem');
        foreach ([\App\Integracoes\Pix\ProvedorPix::class, \App\Integracoes\Mensagens\Mensageiro::class, \App\Integracoes\Fiscal\EmissorFiscal::class] as $c) {
            app()->forgetInstance($c);
        }
    }

    protected function temMarcador(): bool
    {
        return Schema::connection('demo')->hasTable('demo_runs') && DB::connection('demo')->table('demo_runs')->where('acao', 'marcador')->exists();
    }

    protected function credenciais(?string $segredo): void
    {
        $senha = config('oficina.demo.senha');
        $this->newLine();
        $this->line('<options=bold>DEMONSTRAÇÃO — contas fictícias (uso exclusivo do banco de demonstração)</>');
        $this->table(['Conta', 'Senha'], [
            ['dono@demo.oficina.test (proprietária, 2FA)', $senha],
            ['gerente.asn@demo.oficina.test · gerente.tag@… · gerente.agc@…', $senha],
            ['recepcao.asn@demo.oficina.test · recepcao.tag@… · recepcao.agc@…', $senha],
            ['mecanico.asn@demo.oficina.test · mecanico2.asn@… (idem TAG/AGC)', $senha],
        ]);
        if ($segredo) {
            $this->line('Chave 2FA da proprietária (adicionar no autenticador): '.trim(chunk_split($segredo, 4, ' ')));
        }
        $this->line('Para navegar na demo: OFICINA_BANCO=demo no .env e `docker compose up -d`. Roteiro: docs/demo-roteiro.md');
    }
}
