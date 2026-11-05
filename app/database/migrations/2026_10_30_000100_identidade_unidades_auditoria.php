<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 10)->unique();
            $table->string('nome', 120);
            $table->boolean('ativa')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('papel', 20)->default('mecanico');
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->restrictOnDelete();
            $table->boolean('ativo')->default(true);
            $table->jsonb('permissoes_extras')->default('[]');
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->index(['unidade_id', 'papel']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_papel_valido CHECK (papel IN ('proprietario','gerente','recepcao','mecanico'))");
        // Somente o proprietário tem visão global; os demais papéis pertencem a uma unidade.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_unidade_obrigatoria CHECK (papel = 'proprietario' OR unidade_id IS NOT NULL)");

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('ocorrido_em')->useCurrent();
            $table->foreignId('ator_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('ator_tipo', 20)->default('usuario');
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->restrictOnDelete();
            $table->string('acao', 80);
            $table->string('entidade_tipo', 60)->nullable();
            $table->unsignedBigInteger('entidade_id')->nullable();
            $table->jsonb('antes')->nullable();
            $table->jsonb('depois')->nullable();
            $table->text('motivo')->nullable();
            $table->uuid('correlacao')->nullable();
            $table->index(['unidade_id', 'ocorrido_em']);
            $table->index(['entidade_tipo', 'entidade_id']);
        });

        // Append-only no nível do banco para o usuário da aplicação.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_somente_insercao() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events é somente inserção';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER audit_events_sem_alteracao BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_somente_insercao();
            CREATE TRIGGER audit_events_sem_truncate BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION audit_events_somente_insercao();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_somente_insercao()');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_unidade_obrigatoria, DROP CONSTRAINT IF EXISTS users_papel_valido');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unidade_id');
            $table->dropColumn(['papel', 'ativo', 'permissoes_extras', 'two_factor_secret', 'two_factor_confirmed_at']);
        });
        Schema::dropIfExists('unidades');
    }
};
