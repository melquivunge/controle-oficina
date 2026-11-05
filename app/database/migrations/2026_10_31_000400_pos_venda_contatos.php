<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pós-venda (decisões de 30/10/2026, item 8): contatos com o cliente depois da entrega.
 * Cada linha é uma tentativa; problema no serviço e negociação fora do padrão viram pendências resolvíveis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_venda_contatos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $table->string('tentativa', 20); // ligacao | mensagem
            $table->string('resultado', 20); // atendeu | nao_atendeu | numero_errado
            $table->unsignedSmallInteger('nota')->nullable(); // NPS 0–10, só quando o cliente respondeu
            $table->text('comentario')->nullable();
            $table->boolean('problema_servico')->default(false);
            $table->text('problema_descricao')->nullable();
            $table->boolean('negociacao_fora_padrao')->default(false);
            $table->text('negociacao_descricao')->nullable();
            $table->date('retorno_em')->nullable(); // retorno agendado (data local)
            $table->timestampTz('registrado_em');
            $table->index(['ordem_id', 'id']);
            $table->index(['unidade_id', 'registrado_em']);
        });
        DB::statement("ALTER TABLE pos_venda_contatos ADD CONSTRAINT pos_venda_valores CHECK (
            tentativa IN ('ligacao','mensagem')
            AND resultado IN ('atendeu','nao_atendeu','numero_errado')
            AND (nota IS NULL OR (nota BETWEEN 0 AND 10 AND resultado = 'atendeu'))
            AND (NOT problema_servico OR problema_descricao IS NOT NULL)
            AND (NOT negociacao_fora_padrao OR negociacao_descricao IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_venda_contatos');
    }
};
