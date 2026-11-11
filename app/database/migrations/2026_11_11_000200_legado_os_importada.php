<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** OS do sistema antigo importada para uma OS nova (11/11/2026): cada OS antiga só vira OS uma vez. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legado_registros', fn (Blueprint $t) => $t->foreignId('ordem_id')->nullable()->unique()->constrained('ordens')->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('legado_registros', fn (Blueprint $t) => $t->dropConstrainedForeignId('ordem_id'));
    }
};
