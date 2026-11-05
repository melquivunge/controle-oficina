<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conta oficial de recebimento por unidade (somente proprietário altera, com reautenticação).
        Schema::create('recebedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->string('provedor', 30);
            $table->string('identificador_conta', 120); // conta/chave no provedor, validada na confirmação
            $table->string('descricao', 120);
            $table->boolean('ativo')->default(true);
            $table->foreignId('criado_por')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX recebedores_um_ativo ON recebedores (unidade_id) WHERE ativo');

        Schema::create('cobrancas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('orcamento_id')->constrained('orcamentos')->restrictOnDelete();
            $table->foreignId('recebedor_id')->constrained('recebedores')->restrictOnDelete();
            $table->string('provedor', 30);
            $table->string('txid', 64)->nullable()->unique();
            $table->string('chave_idempotencia', 64)->unique();
            $table->bigInteger('valor_centavos');
            $table->string('status', 20)->default('criando'); // criando|pendente|paga|expirada|cancelada|falha
            $table->text('copia_e_cola')->nullable();
            $table->timestampTz('expira_em')->nullable();
            $table->text('erro')->nullable();
            $table->foreignId('criado_por')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'expira_em']);
        });
        DB::statement("ALTER TABLE cobrancas ADD CONSTRAINT cobrancas_valores CHECK (valor_centavos > 0 AND status IN ('criando','pendente','paga','expirada','cancelada','falha'))");
        DB::statement("CREATE UNIQUE INDEX cobrancas_uma_aberta ON cobrancas (ordem_id) WHERE status IN ('criando','pendente')");

        Schema::create('pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('cobranca_id')->nullable()->constrained('cobrancas')->restrictOnDelete();
            $table->string('meio', 20); // pix | dinheiro | cartao_externo
            $table->bigInteger('valor_centavos');
            // confirmado (provedor) | registrado (manual, revisão no fechamento) | em_revisao (divergência)
            $table->string('status', 20);
            $table->text('motivo_revisao')->nullable();
            $table->string('id_provedor', 100)->nullable()->unique(); // e2eid: repetição não duplica crédito
            $table->timestampTz('recebido_em');
            $table->foreignId('registrado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('fechamento_id')->nullable();
            $table->timestampTz('revisado_em')->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['unidade_id', 'recebido_em']);
        });
        DB::statement("ALTER TABLE pagamentos ADD CONSTRAINT pagamentos_valores CHECK (valor_centavos > 0 AND meio IN ('pix','dinheiro','cartao_externo') AND status IN ('confirmado','registrado','em_revisao'))");
        // Pix só entra por confirmação do provedor; manual nunca é Pix.
        DB::statement("ALTER TABLE pagamentos ADD CONSTRAINT pagamentos_pix_do_provedor CHECK ((meio = 'pix') = (id_provedor IS NOT NULL) AND (meio <> 'pix' OR registrado_por IS NULL))");

        Schema::create('devolucoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pagamento_id')->constrained('pagamentos')->restrictOnDelete();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->bigInteger('valor_centavos');
            $table->text('motivo');
            $table->foreignId('autorizado_por')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE devolucoes ADD CONSTRAINT devolucoes_valor CHECK (valor_centavos > 0)');

        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->string('provedor', 30);
            $table->string('evento_id', 120);
            $table->string('tipo', 60);
            $table->jsonb('payload');
            $table->timestampTz('recebido_em');
            $table->timestampTz('processado_em')->nullable();
            $table->string('resultado', 60)->nullable();
            $table->text('erro')->nullable();
            $table->unique(['provedor', 'evento_id']); // deduplicação
        });

        Schema::create('fechamentos_caixa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->date('data'); // dia local (America/Sao_Paulo)
            $table->bigInteger('dinheiro_sistema_centavos');
            $table->bigInteger('dinheiro_contado_centavos');
            $table->bigInteger('cartao_sistema_centavos');
            $table->bigInteger('pix_confirmado_centavos');
            $table->text('observacao')->nullable();
            $table->foreignId('fechado_por')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['unidade_id', 'data']);
        });
        Schema::table('pagamentos', fn (Blueprint $t) => $t->foreign('fechamento_id')->references('id')->on('fechamentos_caixa')->restrictOnDelete());

        Schema::create('entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_id')->unique()->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('entregue_por')->constrained('users')->restrictOnDelete();
            $table->bigInteger('saldo_centavos'); // saldo no instante da entrega
            $table->boolean('excecao')->default(false);
            $table->text('excecao_motivo')->nullable();
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('entregue_em');
        });
        DB::statement('ALTER TABLE entregas ADD CONSTRAINT entregas_excecao CHECK ((saldo_centavos = 0 AND NOT excecao) OR (excecao AND excecao_motivo IS NOT NULL AND autorizado_por IS NOT NULL))');

        // Estado do provedor simulado (substitui o provedor externo no modo simulado).
        Schema::create('simulador_pix', function (Blueprint $table) {
            $table->string('txid', 64)->primary();
            $table->string('conta', 120);
            $table->bigInteger('valor_centavos');
            $table->string('status', 20);
            $table->bigInteger('valor_pago_centavos')->nullable();
            $table->string('e2e_id', 100)->nullable();
            $table->timestampTz('pago_em')->nullable();
            $table->timestampTz('expira_em');
        });
    }

    public function down(): void
    {
        foreach (['simulador_pix', 'entregas', 'devolucoes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('pagamentos', fn (Blueprint $t) => $t->dropForeign(['fechamento_id']));
        foreach (['fechamentos_caixa', 'integration_events', 'pagamentos', 'cobrancas', 'recebedores'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
