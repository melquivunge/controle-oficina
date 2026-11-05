<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class DemoReset extends DemoBase
{
    protected $signature = 'demo:reset {--confirmar= : Digite RESETAR-DEMO para confirmar sem pergunta interativa}';

    protected $description = 'Apaga e recarrega SOMENTE o banco de demonstração (exige confirmação e marcador).';

    public function handle(): int
    {
        try {
            $this->guardas();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $db = DB::connection('demo');
        $vazio = count(Schema::connection('demo')->getTableListing()) === 0;
        if (! $vazio && ! $this->temMarcador()) {
            $this->error('Banco sem marcador de demonstração: reset recusado. Nada foi alterado.');

            return self::FAILURE;
        }
        $confirmacao = $this->option('confirmar') ?? ($this->input->isInteractive() ? $this->ask('Isto apaga todos os dados do banco "'.$db->getDatabaseName().'". Digite RESETAR-DEMO para confirmar') : null);
        if ($confirmacao !== 'RESETAR-DEMO') {
            $this->error('Reset não confirmado. Nada foi alterado.');

            return self::FAILURE;
        }
        $lock = Cache::lock('oficina-demo-manutencao', 1800);
        if (! $lock->get()) {
            $this->error('Outra carga ou reset da demonstração está em andamento.');

            return self::FAILURE;
        }
        try {
            // Banco comprovadamente descartável: conexão demo validada + marcador próprio.
            Artisan::call('db:wipe', ['--database' => 'demo', '--force' => true]);
            File::deleteDirectory(storage_path('app/private/demo'));
            $this->info('Banco de demonstração apagado. Recarregando...');
            $r = app(DemoLoad::class);
            $r->setLaravel($this->laravel);
            $r->setOutput($this->output);

            $status = $r->carregar();
            DB::connection('demo')->table('demo_runs')->insert(['acao' => 'reset', 'executado_em' => now()]);

            return $status;
        } finally {
            $lock->release();
        }
    }
}
