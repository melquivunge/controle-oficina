<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Vendedor da OS: gerente/proprietário da unidade que fechou a venda (padrão: quem enviou o orçamento). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens', function (Blueprint $table) {
            $table->foreignId('vendedor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['unidade_id', 'vendedor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ordens', function (Blueprint $table) {
            $table->dropIndex(['unidade_id', 'vendedor_id']);
            $table->dropConstrainedForeignId('vendedor_id');
        });
    }
};
