<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Executado somente pelo serviço init (conexão dona do esquema). Cria/atualiza o papel de runtime
 * `oficina_app`, sem privilégio de DDL, e aplica as permissões após as migrações.
 */
class PrepararBanco extends Command
{
    protected $signature = 'oficina:preparar-banco {--permissoes : Apenas reaplicar permissões (após migrar)}';

    protected $description = 'Prepara papel de runtime do banco e permissões (uso do serviço init).';

    public function handle(): int
    {
        $senha = (string) env('APP_DB_PASSWORD');
        if ($senha === '') {
            $this->error('APP_DB_PASSWORD ausente. Defina no .env.');

            return self::FAILURE;
        }
        $db = DB::connection('pgsql'); // sempre o banco principal, mesmo com OFICINA_BANCO=demo
        $banco = $db->getDatabaseName();

        if (! $this->option('permissoes')) {
            $existe = $db->selectOne("select 1 as x from pg_roles where rolname = 'oficina_app'");
            $db->statement(($existe ? 'ALTER' : 'CREATE').' ROLE oficina_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '.$db->getPdo()->quote($senha));
            $db->statement("GRANT CONNECT ON DATABASE \"{$banco}\" TO oficina_app");
            $this->info('init: papel de runtime oficina_app pronto.');

            return self::SUCCESS;
        }

        $db->unprepared(<<<'SQL'
            GRANT USAGE ON SCHEMA public TO oficina_app;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO oficina_app;
            GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO oficina_app;
            REVOKE UPDATE, DELETE, TRUNCATE ON audit_events FROM oficina_app;
        SQL);
        $this->info('init: permissões de runtime aplicadas (audit_events somente INSERT/SELECT).');

        return self::SUCCESS;
    }
}
