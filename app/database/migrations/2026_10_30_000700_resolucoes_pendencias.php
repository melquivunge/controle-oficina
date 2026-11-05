<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resolução registrada pelo gerente para pendências cuja origem não guarda resolução própria.
        // Divergências de conferência continuam usando a resolução da própria tabela.
        Schema::create('resolucoes_pendencias', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 30); // entrega_excecao | cobranca_falha | mensagem_falhou | fiscal_rejeitado
            $table->unsignedBigInteger('referencia_id');
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->text('texto');
            $table->foreignId('resolvido_por')->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolvido_em');
            $table->unique(['tipo', 'referencia_id']); // uma resolução por pendência
        });
        DB::statement("ALTER TABLE resolucoes_pendencias ADD CONSTRAINT resolucoes_tipo CHECK (tipo IN ('entrega_excecao','cobranca_falha','mensagem_falhou','fiscal_rejeitado'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('resolucoes_pendencias');
    }
};
