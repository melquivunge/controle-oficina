<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dispensa individual da verificação em duas etapas (decisão do usuário, 10/11/2026). Padrão continua obrigatório. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('dois_fatores_dispensado')->default(false));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('dois_fatores_dispensado'));
    }
};
