<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outbox de mensagens: gravada na mesma transação do fato; despachada após o commit e varrida periodicamente.
        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->string('tipo', 20); // entrada | orcamento | cobranca | conclusao
            $table->string('canal', 30);
            $table->string('destino', 20);
            $table->text('conteudo'); // link do cliente substituído por marcador
            $table->text('segredo_link')->nullable(); // token cifrado; apagado após envio
            $table->string('status', 20)->default('pendente'); // pendente|enviado|falhou
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->text('ultimo_erro')->nullable();
            $table->string('id_provedor', 100)->nullable();
            $table->timestampTz('enviado_em')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'updated_at']);
        });
        DB::statement("ALTER TABLE message_deliveries ADD CONSTRAINT mensagens_valores CHECK (tipo IN ('entrada','orcamento','cobranca','conclusao') AND status IN ('pendente','enviado','falhou'))");

        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('orcamento_id')->constrained('orcamentos')->restrictOnDelete();
            $table->bigInteger('valor_centavos');
            $table->string('status', 20)->default('pendente');
            $table->string('provedor', 30);
            $table->string('chave_idempotencia', 64)->unique();
            $table->string('numero', 40)->nullable();
            $table->string('protocolo', 80)->nullable();
            $table->string('arquivo', 255)->nullable(); // documento retornado, preservado em disco privado
            $table->text('mensagem')->nullable(); // motivo de rejeição/erro
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->foreignId('solicitado_por')->constrained('users')->restrictOnDelete();
            $table->timestampTz('autorizado_em')->nullable();
            $table->timestampTz('cancelado_em')->nullable();
            $table->text('cancelamento_motivo')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE fiscal_documents ADD CONSTRAINT fiscal_valores CHECK (valor_centavos > 0 AND status IN ('pendente','processando','autorizado','rejeitado','cancelado'))");
        DB::statement("CREATE UNIQUE INDEX fiscal_um_ativo ON fiscal_documents (ordem_id) WHERE status IN ('pendente','processando','autorizado')");

        Schema::create('simulador_fiscal', function (Blueprint $table) {
            $table->string('chave', 64)->primary();
            $table->string('status', 20);
            $table->string('numero', 40)->nullable();
            $table->string('protocolo', 80)->nullable();
            $table->text('mensagem')->nullable();
            $table->timestampTz('criado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulador_fiscal');
        Schema::dropIfExists('fiscal_documents');
        Schema::dropIfExists('message_deliveries');
    }
};
