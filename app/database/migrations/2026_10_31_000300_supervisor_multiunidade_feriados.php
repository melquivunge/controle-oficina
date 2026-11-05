<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supervisor multiunidade (decisões de 30/10/2026: Goiânia sem gerente) e feriados estaduais/municipais por unidade.
 * O supervisor mantém unidade_id (unidade principal, exigida pelo CHECK existente) e acessa as unidades da tabela pivô.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_papel_valido');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_papel_valido CHECK (papel IN ('proprietario','gerente','supervisor','recepcao','mecanico'))");

        Schema::create('usuario_unidades', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'unidade_id']);
            $table->index('unidade_id');
        });

        Schema::create('feriados_unidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->date('data');
            $table->string('descricao', 120);
            $table->foreignId('criado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['unidade_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feriados_unidade');
        Schema::dropIfExists('usuario_unidades');
        DB::statement("UPDATE users SET papel = 'gerente' WHERE papel = 'supervisor'");
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_papel_valido');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_papel_valido CHECK (papel IN ('proprietario','gerente','recepcao','mecanico'))");
    }
};
