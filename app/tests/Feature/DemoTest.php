<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/** Demo em banco de teste separado (oficina_teste_demo), sem tocar no banco principal de testes. */
class DemoTest extends TestCase
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
        config(['database.connections.demo' => array_merge(config('database.connections.pgsql'), ['database' => 'oficina_teste_demo']), 'oficina.demo.dias' => (int) env('OFICINA_DEMO_DIAS_TESTE', 8)]);
        DB::purge('demo');
        $this->artisan('db:wipe', ['--database' => 'demo', '--force' => true]);
    }

    private function contar(): array
    {
        $d = DB::connection('demo');

        return ['unidades' => $d->table('unidades')->count(), 'users' => $d->table('users')->count(), 'ordens' => $d->table('ordens')->count(),
            'pagamentos' => $d->table('pagamentos')->count(), 'auditoria' => $d->table('audit_events')->count()];
    }

    public function test_carga_explicita_idempotente_coerente_e_isolada_do_banco_principal(): void
    {
        $principalAntes = DB::connection('pgsql')->table('ordens')->count();
        $this->artisan('demo:load')->assertSuccessful()->expectsOutputToContain('Demonstração carregada');
        $primeira = $this->contar();
        $this->assertSame(3, $primeira['unidades']);
        $this->assertSame(14, $primeira['users']); // 1 proprietário + 3 equipes × 4 + supervisor multiunidade (31/10)
        $this->assertGreaterThan(10, $primeira['ordens']);

        $this->artisan('demo:load')->assertSuccessful()->expectsOutputToContain('já carregada');
        $this->assertSame($primeira, $this->contar(), 'segunda carga não duplica');
        $this->assertSame($principalAntes, DB::connection('pgsql')->table('ordens')->count(), 'banco principal intocado');

        $d = DB::connection('demo');
        // Totais coerentes: versão aprovada = soma dos itens − desconto; pagamentos efetivos nunca excedem o aprovado.
        $this->assertSame(0, $d->selectOne('select count(*) n from orcamentos o where total_centavos <> (select coalesce(sum(total_centavos),0) from orcamento_itens i where i.orcamento_id = o.id) - desconto_centavos')->n);
        $this->assertSame(0, $d->selectOne("select count(*) n from ordens o join orcamentos a on a.ordem_id = o.id and a.status = 'aprovado'
            where (select coalesce(sum(valor_centavos),0) from pagamentos p where p.ordem_id = o.id and p.status in ('confirmado','registrado'))
                - (select coalesce(sum(valor_centavos),0) from devolucoes v where v.ordem_id = o.id) > a.total_centavos")->n);
        $this->assertSame(0, $d->selectOne("select count(*) n from ordens o where status = 'entregue' and not exists (select 1 from entregas e where e.ordem_id = o.id)")->n);
        $this->assertSame(0, $d->table('users')->where('email', 'not like', '%@demo.oficina.test')->count(), 'contatos em domínio .test');
        $this->assertSame(0, $d->table('message_deliveries')->where('canal', '<>', 'simulado')->count(), 'nenhuma mensagem real');
        $this->assertSame(0, $d->table('cobrancas')->where('provedor', '<>', 'simulado')->count());
        $this->assertTrue($d->table('demo_runs')->where('acao', 'marcador')->exists());
        // Cenário de devolução Pix recusada pelo provedor SIMULADO: a 1ª devolução do cenário 'devolucao' falha (fica em Pendências).
        // Com 8 dias (padrão do teste) o cenário pode não ocorrer; OFICINA_DEMO_DIAS_TESTE=30 exercita a carga completa.
        if ($d->table('devolucoes')->exists()) {
            $this->assertGreaterThan(0, $d->table('devolucoes')->where('status', 'falhou')->whereNotNull('mensagem')->count(), 'cenário de devolução Pix recusada');
        }
    }

    public function test_carga_reproduzivel_pela_data_de_referencia(): void
    {
        $this->artisan('demo:load')->assertSuccessful();
        $a = [$this->contar(), DB::connection('demo')->table('orcamentos')->sum('total_centavos')];
        $this->artisan('demo:reset', ['--confirmar' => 'RESETAR-DEMO'])->assertSuccessful();
        $b = [$this->contar(), DB::connection('demo')->table('orcamentos')->sum('total_centavos')];
        unset($a[0]['auditoria'], $b[0]['auditoria']);
        $this->assertSame($a, $b);
        $this->assertTrue(DB::connection('demo')->table('demo_runs')->where('acao', 'reset')->exists());
    }

    public function test_reset_exige_confirmacao_e_marcador(): void
    {
        $this->artisan('demo:load')->assertSuccessful();
        $antes = $this->contar();
        $this->artisan('demo:reset', ['--confirmar' => 'sim'])->assertFailed();
        $this->assertSame($antes, $this->contar());

        DB::connection('demo')->table('demo_runs')->where('acao', 'marcador')->delete();
        $this->artisan('demo:reset', ['--confirmar' => 'RESETAR-DEMO'])->assertFailed()->expectsOutputToContain('sem marcador');
        $this->assertSame($antes, $this->contar());
        $this->artisan('demo:load')->assertFailed()->expectsOutputToContain('sem o marcador');
    }

    public function test_bloqueado_em_producao_e_com_banco_igual_ao_principal(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('demo:load')->assertFailed()->expectsOutputToContain('bloqueados em produção');
        $this->artisan('demo:reset', ['--confirmar' => 'RESETAR-DEMO'])->assertFailed();
        $this->app['env'] = 'testing';

        config(['database.connections.demo.database' => config('database.connections.pgsql.database')]);
        DB::purge('demo');
        $this->artisan('demo:reset', ['--confirmar' => 'RESETAR-DEMO'])->assertFailed()->expectsOutputToContain('igual ao principal');
        $this->assertGreaterThan(0, count(\Illuminate\Support\Facades\Schema::connection('pgsql')->getTableListing()), 'principal não foi apagado');
    }
}
