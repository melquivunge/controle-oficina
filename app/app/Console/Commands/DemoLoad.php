<?php

namespace App\Console\Commands;

use App\Demo\GeradorDemo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DemoLoad extends DemoBase
{
    protected $signature = 'demo:load';

    protected $description = 'Carrega um mês de dados fictícios no banco SEPARADO de demonstração (idempotente).';

    public function handle(): int
    {
        try {
            $this->guardas();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $lock = Cache::lock('oficina-demo-manutencao', 1800);
        if (! $lock->get()) {
            $this->error('Outra carga ou reset da demonstração está em andamento.');

            return self::FAILURE;
        }
        try {
            return $this->carregar();
        } finally {
            $lock->release();
        }
    }

    public function carregar(): int
    {
        Artisan::call('migrate', ['--database' => 'demo', '--force' => true]);
        $db = DB::connection('demo');
        if (! $this->temMarcador()) {
            if ($db->table('users')->exists()) {
                $this->error('O banco de demonstração contém dados sem o marcador de demonstração. Nada foi alterado.');

                return self::FAILURE;
            }
            $db->table('demo_runs')->insert(['acao' => 'marcador', 'executado_em' => now()]);
        }
        $versao = config('oficina.demo.versao');
        if ($anterior = $db->table('demo_runs')->where('acao', 'carga')->where('versao', $versao)->first()) {
            $this->info("Demonstração já carregada em {$anterior->executado_em}. Nada foi alterado (alterações feitas na apresentação foram preservadas).");
            $this->credenciais(\App\Support\Totp::base32('demo-oficina-2026-2fa'));

            return self::SUCCESS;
        }
        if ($db->table('ordens')->exists()) {
            $this->error('Carga anterior incompleta detectada. Execute demo:reset para recomeçar.');

            return self::FAILURE;
        }

        $this->usarDemo();
        $referencia = CarbonImmutable::parse(config('oficina.demo.referencia'), 'America/Sao_Paulo')->setTime(18, 0);
        $this->info('Gerando um mês de movimento fictício até '.$referencia->format('d/m/Y').' (pode levar alguns minutos)...');
        $r = (new GeradorDemo)->gerar($referencia);
        $db->table('demo_runs')->insert(['acao' => 'carga', 'versao' => $versao, 'referencia' => $referencia->toDateString(),
            'resumo' => json_encode($r['resumo']), 'executado_em' => now()]);
        $this->info('Demonstração carregada: '.json_encode($r['resumo'], JSON_UNESCAPED_UNICODE));
        $this->credenciais($r['segredo_2fa']);

        return self::SUCCESS;
    }
}
