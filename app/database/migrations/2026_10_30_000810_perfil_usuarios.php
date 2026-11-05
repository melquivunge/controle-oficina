<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Perfil preenchido pelo próprio usuário: telefone, cargo e foto (arquivo privado). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telefone', 11)->nullable();
            $table->string('cargo', 80)->nullable();
            $table->string('foto_caminho')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['telefone', 'cargo', 'foto_caminho']));
    }
};
