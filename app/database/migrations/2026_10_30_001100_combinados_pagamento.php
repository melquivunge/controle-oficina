<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloco B: combinado de pagamento do saldo (parcelas previstas com vencimento).
 * A situação de cada parcela é derivada dos recebimentos efetivos (nada é marcado à mão).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combinados_pagamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('orcamento_id')->constrained('orcamentos')->restrictOnDelete(); // versão aprovada no momento do combinado
            $table->bigInteger('saldo_centavos'); // saldo da OS no instante do combinado = soma das parcelas
            $table->string('status', 20)->default('vigente'); // vigente | substituido | cancelado
            $table->text('motivo_encerramento')->nullable();
            $table->foreignId('criado_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('encerrado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('encerrado_em')->nullable();
            $table->timestampsTz();
            $table->index(['unidade_id', 'status']);
        });
        DB::statement("ALTER TABLE combinados_pagamento ADD CONSTRAINT combinados_valores CHECK (saldo_centavos > 0 AND status IN ('vigente','substituido','cancelado'))");
        DB::statement("ALTER TABLE combinados_pagamento ADD CONSTRAINT combinados_encerramento CHECK ((status = 'vigente') = (encerrado_em IS NULL) AND (status = 'vigente' OR (motivo_encerramento IS NOT NULL AND encerrado_por IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX combinados_um_vigente ON combinados_pagamento (ordem_id) WHERE status = 'vigente'");

        Schema::create('parcelas_previstas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combinado_id')->constrained('combinados_pagamento')->restrictOnDelete();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->smallInteger('sequencia');
            $table->bigInteger('valor_centavos');
            $table->date('vencimento'); // dia local (America/Sao_Paulo)
            $table->string('meio_previsto', 20)->nullable();
            $table->string('observacao', 300)->nullable();
            $table->timestampsTz();
            $table->unique(['combinado_id', 'sequencia']);
            $table->index(['unidade_id', 'vencimento']);
        });
        DB::statement("ALTER TABLE parcelas_previstas ADD CONSTRAINT parcelas_valores CHECK (valor_centavos > 0 AND sequencia > 0 AND (meio_previsto IS NULL OR meio_previsto IN ('pix','dinheiro','cartao_externo')))");

        // Entrega com saldo coberto por combinado vigente: sem exceção, mas registra o combinado.
        Schema::table('entregas', fn (Blueprint $t) => $t->foreignId('combinado_id')->nullable()->constrained('combinados_pagamento')->restrictOnDelete());
        DB::statement('ALTER TABLE entregas DROP CONSTRAINT entregas_excecao');
        DB::statement('ALTER TABLE entregas ADD CONSTRAINT entregas_excecao CHECK ((saldo_centavos = 0 AND NOT excecao AND combinado_id IS NULL) OR (excecao AND excecao_motivo IS NOT NULL AND autorizado_por IS NOT NULL AND combinado_id IS NULL) OR (saldo_centavos > 0 AND NOT excecao AND combinado_id IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE entregas DROP CONSTRAINT entregas_excecao');
        Schema::table('entregas', fn (Blueprint $t) => $t->dropConstrainedForeignId('combinado_id'));
        DB::statement('ALTER TABLE entregas ADD CONSTRAINT entregas_excecao CHECK ((saldo_centavos = 0 AND NOT excecao) OR (excecao AND excecao_motivo IS NOT NULL AND autorizado_por IS NOT NULL))');
        Schema::dropIfExists('parcelas_previstas');
        Schema::dropIfExists('combinados_pagamento');
    }
};
