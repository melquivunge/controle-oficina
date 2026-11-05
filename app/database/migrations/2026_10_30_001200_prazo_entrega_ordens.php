<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bloco A: data de entrega prevista (dias úteis), definida por gerente/proprietário. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens', function (Blueprint $table) {
            $table->date('entrega_prevista_em')->nullable();
            $table->foreignId('entrega_prevista_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('entrega_prevista_definida_em')->nullable();
            $table->index(['unidade_id', 'entrega_prevista_em']);
        });
    }

    public function down(): void
    {
        Schema::table('ordens', function (Blueprint $table) {
            $table->dropIndex(['unidade_id', 'entrega_prevista_em']);
            $table->dropConstrainedForeignId('entrega_prevista_por');
            $table->dropColumn(['entrega_prevista_em', 'entrega_prevista_definida_em']);
        });
    }
};
