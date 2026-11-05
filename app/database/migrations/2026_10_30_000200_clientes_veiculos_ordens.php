<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cadastro único da rede (CRM básico). Ordens e valores ficam isolados por unidade.
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 150);
            $table->string('telefone', 20)->index(); // só dígitos
            $table->string('email')->nullable();
            $table->text('observacoes')->nullable();
            $table->foreignId('mesclado_em_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement('CREATE INDEX clientes_nome_busca ON clientes (lower(nome) text_pattern_ops)');

        Schema::create('veiculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('placa', 7)->unique(); // maiúsculas, sem hífen
            $table->string('modelo', 80)->nullable();
            $table->smallInteger('ano')->nullable();
            $table->string('cor', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('unidade_sequencias', function (Blueprint $table) {
            $table->foreignId('unidade_id')->primary()->constrained('unidades')->restrictOnDelete();
            $table->unsignedInteger('ultimo_numero')->default(0);
        });

        Schema::create('ordens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->unsignedInteger('sequencia');
            $table->string('numero', 20)->unique();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('veiculo_id')->constrained('veiculos')->restrictOnDelete();
            $table->string('status', 30)->default('entrada');
            $table->text('motivo');
            $table->unsignedInteger('quilometragem')->nullable();
            $table->text('observacoes')->nullable();
            $table->text('diagnostico')->nullable();
            $table->timestampTz('entrada_em');
            $table->foreignId('criado_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('mecanico_id')->nullable()->constrained('users')->restrictOnDelete();
            // RF22: atendimento registrado depois de indisponibilidade.
            $table->boolean('contingencia')->default(false);
            $table->text('contingencia_nota')->nullable();
            $table->text('cancelamento_motivo')->nullable();
            $table->timestamps();
            $table->unique(['unidade_id', 'sequencia']);
            $table->index(['unidade_id', 'status']);
            $table->index(['unidade_id', 'entrada_em']);
            $table->index('mecanico_id');
        });
        DB::statement("ALTER TABLE ordens ADD CONSTRAINT ordens_status_valido CHECK (status IN ('entrada','diagnostico','aguardando_aprovacao','autorizado','em_execucao','pronto_para_entrega','entregue','cancelado'))");
        DB::statement("ALTER TABLE ordens ADD CONSTRAINT ordens_cancelamento_com_motivo CHECK (status <> 'cancelado' OR cancelamento_motivo IS NOT NULL)");

        Schema::create('anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->string('caminho');
            $table->string('nome_original', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('tamanho');
            $table->foreignId('enviado_por')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anexos');
        Schema::dropIfExists('ordens');
        Schema::dropIfExists('unidade_sequencias');
        Schema::dropIfExists('veiculos');
        Schema::dropIfExists('clientes');
    }
};
