<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/** Cenários fictícios da Central de WhatsApp na demonstração (banco separado oficina_teste_demo, como DemoTest). */
class CentralWhatsAppDemoTest extends TestCase
{
    public function beginDatabaseTransaction(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('pgsql:host='.env('DB_HOST').';dbname=postgres', env('DB_USERNAME'), env('DB_PASSWORD'));
        if (! $pdo->query("select 1 from pg_database where datname = 'oficina_teste_demo'")->fetchColumn()) {
            $pdo->exec('CREATE DATABASE oficina_teste_demo');
        }
        config(['database.connections.demo' => array_merge(config('database.connections.pgsql'), ['database' => 'oficina_teste_demo']), 'oficina.demo.dias' => 3]);
        DB::purge('demo');
        $this->artisan('db:wipe', ['--database' => 'demo', '--force' => true]);
    }

    public function test_demo_gera_conversas_ficticias_de_varias_situacoes_sem_nada_real(): void
    {
        $this->artisan('demo:load')->assertSuccessful();
        $d = DB::connection('demo');
        $this->assertSame(4, $d->table('whatsapp_numeros')->count(), 'central + uma por unidade');
        $this->assertSame(0, $d->table('whatsapp_numeros')->where('provedor', '<>', 'simulado')->count());
        $this->assertGreaterThan(0, $d->table('whatsapp_conversas')->whereNull('unidade_id')->count(), 'triagem');
        $this->assertGreaterThan(0, $d->table('whatsapp_conversas')->whereNotNull('aguardando_desde')->where('situacao', '<>', 'resolvida')->count(), 'sem resposta');
        $this->assertGreaterThan(0, $d->table('whatsapp_conversas')->where('situacao', 'resolvida')->count());
        $this->assertGreaterThan(0, $d->table('whatsapp_mensagens')->where('origem', 'celular')->count(), 'resposta pelo celular');
        $this->assertGreaterThan(0, $d->table('whatsapp_mensagens')->where('status', 'falhou')->count(), 'envio falho visível');
        $this->assertGreaterThan(0, $d->table('whatsapp_mensagens')->where('status', 'lida')->count());
        $this->assertSame(3, $d->table('whatsapp_mensagens')->where('midia_status', 'baixada')->count(), 'imagem, áudio e documento simulados');
        $this->assertGreaterThan(0, $d->table('audit_events')->where('acao', 'whatsapp_encaminhada')->count());
        $this->assertSame(0, $d->table('whatsapp_mensagens')->where('direcao', 'saida')->whereNotNull('id_provedor')
            ->where('id_provedor', 'not like', 'SIM-%')->where('id_provedor', 'not like', 'DEMO-%')->count(), 'nenhum id de provedor real');
        $this->assertSame(0, DB::connection('pgsql')->table('whatsapp_conversas')->count(), 'banco principal intocado');
    }
}
