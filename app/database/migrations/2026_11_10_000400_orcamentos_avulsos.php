<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamento sem entrada do veículo (decisão do usuário, 10/11/2026): cadastro à parte das OS, com número da mesma
 * sequência da unidade. Aprovado + carro chegou → vira OS com o mesmo número e os itens num rascunho de orçamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamentos_avulsos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $t->unsignedInteger('sequencia');
            $t->string('numero', 20)->unique();
            $t->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $t->foreignId('conversa_id')->nullable()->constrained('whatsapp_conversas')->nullOnDelete();
            $t->string('placa', 7)->nullable();
            $t->string('veiculo_descricao', 120)->nullable();
            $t->jsonb('itens')->default('[]');
            $t->bigInteger('total_centavos')->default(0);
            $t->text('observacao')->nullable();
            $t->date('validade');
            $t->string('status', 20)->default('rascunho');
            $t->char('token_hash', 64)->nullable()->unique();
            $t->timestampTz('link_expira_em')->nullable();
            $t->timestampTz('enviado_em')->nullable();
            $t->timestampTz('decidido_em')->nullable();
            $t->string('decisao_canal', 20)->nullable();
            $t->text('decisao_observacao')->nullable();
            $t->foreignId('decidido_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('ordem_id')->nullable()->unique()->constrained('ordens')->restrictOnDelete();
            $t->foreignId('criado_por')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['unidade_id', 'sequencia']);
            $t->index(['status', 'unidade_id']);
        });
        DB::statement("ALTER TABLE orcamentos_avulsos ADD CONSTRAINT orcamentos_avulsos_valores CHECK (
            status IN ('rascunho','enviado','aprovado','recusado','convertido','cancelado') AND total_centavos >= 0
            AND (status <> 'convertido' OR ordem_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamentos_avulsos');
    }
};
