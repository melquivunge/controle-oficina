<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conferencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->date('data');
            $table->foreignId('realizada_por')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('esperados');
            $table->unsignedSmallInteger('presentes');
            $table->text('observacao')->nullable();
            $table->timestampsTz();
            $table->index(['unidade_id', 'data']);
        });

        Schema::create('divergencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conferencia_id')->constrained('conferencias')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->string('tipo', 30); // veiculo_sem_ordem | ordem_sem_veiculo
            $table->string('placa', 7);
            $table->foreignId('ordem_id')->nullable()->constrained('ordens')->restrictOnDelete();
            $table->foreignId('revisor_id')->constrained('users')->restrictOnDelete(); // responsável pela revisão
            $table->text('resolucao')->nullable();
            $table->foreignId('resolvido_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolvido_em')->nullable();
            $table->timestampsTz();
            $table->index(['unidade_id', 'resolvido_em']);
        });
        DB::statement("ALTER TABLE divergencias ADD CONSTRAINT divergencias_tipo CHECK (tipo IN ('veiculo_sem_ordem','ordem_sem_veiculo'))");

        // Marcador e histórico de cargas da demonstração (só usado no banco de demonstração).
        Schema::create('demo_runs', function (Blueprint $table) {
            $table->id();
            $table->string('acao', 20); // marcador | carga | reset
            $table->string('versao', 20)->nullable();
            $table->date('referencia')->nullable();
            $table->jsonb('resumo')->nullable();
            $table->timestampTz('executado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_runs');
        Schema::dropIfExists('divergencias');
        Schema::dropIfExists('conferencias');
    }
};
