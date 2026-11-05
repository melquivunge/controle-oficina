<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Devolução Pix passa a ser solicitada ao provedor: processando → concluida | falhou.
        // Registros existentes eram efetivos (só registro manual) e nascem concluídos.
        Schema::table('devolucoes', function (Blueprint $table) {
            $table->string('status', 20)->default('concluida');
            $table->text('mensagem')->nullable();
            $table->string('chave_idempotencia', 80)->nullable();
            $table->string('id_provedor', 100)->nullable();
            $table->timestampTz('concluida_em')->nullable();
        });
        DB::statement("UPDATE devolucoes SET chave_idempotencia = 'legado-' || id, concluida_em = created_at");
        DB::statement('ALTER TABLE devolucoes ALTER COLUMN chave_idempotencia SET NOT NULL');
        Schema::table('devolucoes', fn (Blueprint $t) => $t->unique('chave_idempotencia'));
        DB::statement("ALTER TABLE devolucoes ADD CONSTRAINT devolucoes_status CHECK (status IN ('processando','concluida','falhou'))");
        DB::statement("ALTER TABLE devolucoes ADD CONSTRAINT devolucoes_concluida CHECK ((status = 'concluida') = (concluida_em IS NOT NULL))");
        DB::statement('CREATE INDEX devolucoes_pagamento_status ON devolucoes (pagamento_id, status)');

        // Nova pendência resolvível: devolução recusada pelo provedor.
        DB::statement('ALTER TABLE resolucoes_pendencias DROP CONSTRAINT resolucoes_tipo');
        DB::statement("ALTER TABLE resolucoes_pendencias ADD CONSTRAINT resolucoes_tipo CHECK (tipo IN ('entrega_excecao','cobranca_falha','mensagem_falhou','fiscal_rejeitado','devolucao_falhou'))");

        // Estado do provedor simulado para devoluções (idempotente pela chave).
        Schema::create('simulador_pix_devolucoes', function (Blueprint $table) {
            $table->string('chave_idempotencia', 80)->primary();
            $table->string('e2e_id', 100);
            $table->bigInteger('valor_centavos');
            $table->string('status', 20); // devolvida | recusada
            $table->string('id_devolucao', 100)->nullable();
            $table->text('mensagem')->nullable();
            $table->integer('tentativas')->default(1);
            $table->timestampTz('atualizado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulador_pix_devolucoes');
        DB::statement("DELETE FROM resolucoes_pendencias WHERE tipo = 'devolucao_falhou'");
        DB::statement('ALTER TABLE resolucoes_pendencias DROP CONSTRAINT resolucoes_tipo');
        DB::statement("ALTER TABLE resolucoes_pendencias ADD CONSTRAINT resolucoes_tipo CHECK (tipo IN ('entrega_excecao','cobranca_falha','mensagem_falhou','fiscal_rejeitado'))");
        DB::statement('DROP INDEX IF EXISTS devolucoes_pagamento_status');
        DB::statement('ALTER TABLE devolucoes DROP CONSTRAINT IF EXISTS devolucoes_concluida');
        DB::statement('ALTER TABLE devolucoes DROP CONSTRAINT IF EXISTS devolucoes_status');
        Schema::table('devolucoes', function (Blueprint $table) {
            $table->dropUnique(['chave_idempotencia']);
            $table->dropColumn(['status', 'mensagem', 'chave_idempotencia', 'id_provedor', 'concluida_em']);
        });
    }
};
