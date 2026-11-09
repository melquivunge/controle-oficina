<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arquivo do sistema antigo (09/11/2026): todas as tabelas do SGA, uma linha por registro, só leitura.
 * Isolado: nenhuma tela operacional, indicador, caixa ou numeração consulta esta tabela (não polui o sistema novo).
 * cliente_id/veiculo_id/grupo ligam OS, serviços, peças e notas ao cliente e ao carro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legado_registros', function (Blueprint $t) {
            $t->id();
            $t->string('origem', 20);
            $t->string('tabela', 60);
            $t->unsignedInteger('linha'); // posição na tabela exportada (chave estável da importação)
            $t->string('codigo', 40)->nullable(); // CODIGO original, quando existe
            $t->string('grupo', 60)->nullable(); // ex.: "OS:123" agrupa a OS e seus serviços/peças
            $t->foreignId('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $t->foreignId('veiculo_id')->nullable()->constrained('veiculos')->restrictOnDelete();
            $t->jsonb('dados');
            $t->timestamp('importado_em')->useCurrent();
            $t->unique(['origem', 'tabela', 'linha']);
            $t->index(['cliente_id', 'tabela']);
            $t->index(['origem', 'tabela', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legado_registros');
    }
};
