<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Catálogo de serviços e produtos (31/10/2026): preço de tabela, limite de desconto, adicionais e preço por unidade. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogo_itens', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 150);
            $table->string('categoria', 60);
            $table->string('unidade_venda', 10); // roda | jogo | unidade | hora
            $table->bigInteger('preco_centavos');
            // Desconto máximo sem aprovação (%). Nulo = vale a regra geral (config oficina.desconto_max_geral_percentual).
            $table->decimal('desconto_max_percentual', 5, 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->foreignId('criado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['ativo', 'categoria']);
        });
        DB::statement("ALTER TABLE catalogo_itens ADD CONSTRAINT catalogo_itens_valores CHECK (unidade_venda IN ('roda','jogo','unidade','hora') AND preco_centavos >= 0 AND (desconto_max_percentual IS NULL OR (desconto_max_percentual >= 0 AND desconto_max_percentual <= 100)))");
        DB::statement('CREATE UNIQUE INDEX catalogo_itens_nome_unico ON catalogo_itens (lower(nome))');

        Schema::create('catalogo_adicionais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogo_item_id')->constrained('catalogo_itens')->cascadeOnDelete();
            $table->foreignId('adicional_id')->constrained('catalogo_itens')->cascadeOnDelete();
            $table->unique(['catalogo_item_id', 'adicional_id']);
        });
        DB::statement('ALTER TABLE catalogo_adicionais ADD CONSTRAINT catalogo_adicionais_distintos CHECK (catalogo_item_id <> adicional_id)');

        Schema::create('catalogo_precos_unidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogo_item_id')->constrained('catalogo_itens')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->bigInteger('preco_centavos');
            $table->timestampsTz();
            $table->unique(['catalogo_item_id', 'unidade_id']);
        });
        DB::statement('ALTER TABLE catalogo_precos_unidade ADD CONSTRAINT catalogo_precos_unidade_valor CHECK (preco_centavos >= 0)');

        // Histórico de preço/limite (somente inserção). unidade_id nulo = preço padrão.
        Schema::create('catalogo_precos_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogo_item_id')->constrained('catalogo_itens')->restrictOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->restrictOnDelete();
            $table->bigInteger('preco_anterior_centavos')->nullable();
            $table->bigInteger('preco_novo_centavos')->nullable();
            $table->decimal('desconto_max_anterior', 5, 2)->nullable();
            $table->decimal('desconto_max_novo', 5, 2)->nullable();
            $table->string('origem', 20); // manual | importacao
            $table->foreignId('alterado_por')->constrained('users')->restrictOnDelete();
            $table->timestampTz('alterado_em');
            $table->index(['catalogo_item_id', 'alterado_em']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalogo_historico_imutavel() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'histórico de preço do catálogo é somente inserção'; END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER catalogo_historico_imutavel BEFORE UPDATE OR DELETE ON catalogo_precos_historico
                FOR EACH ROW EXECUTE FUNCTION catalogo_historico_imutavel();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_precos_historico');
        DB::unprepared('DROP FUNCTION IF EXISTS catalogo_historico_imutavel()');
        Schema::dropIfExists('catalogo_precos_unidade');
        Schema::dropIfExists('catalogo_adicionais');
        Schema::dropIfExists('catalogo_itens');
    }
};
