<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importação do sistema antigo (SGA, 09/11/2026): documento (CPF/CNPJ) e o registro original COMPLETO em `legado`
 * (nenhum campo perdido). legado_origem+legado_codigo tornam a importação repetível sem duplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->string('documento', 14)->nullable(); // CPF (11) ou CNPJ (14), só dígitos
            $t->string('legado_origem', 20)->nullable();
            $t->unsignedInteger('legado_codigo')->nullable();
            $t->jsonb('legado')->nullable();
            $t->unique(['legado_origem', 'legado_codigo']);
        });
        Schema::table('veiculos', function (Blueprint $t) {
            $t->string('legado_origem', 20)->nullable();
            $t->unsignedInteger('legado_codigo')->nullable();
            $t->jsonb('legado')->nullable();
            $t->unique(['legado_origem', 'legado_codigo']);
        });
    }

    public function down(): void
    {
        Schema::table('veiculos', fn (Blueprint $t) => $t->dropColumn(['legado_origem', 'legado_codigo', 'legado']));
        Schema::table('clientes', fn (Blueprint $t) => $t->dropColumn(['documento', 'legado_origem', 'legado_codigo', 'legado']));
    }
};
