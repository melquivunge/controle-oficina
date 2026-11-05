<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamento usando o catálogo (31/10/2026): item guarda catálogo, preço de tabela e limite de desconto vigentes no rascunho;
 * tudo congelado no envio pelo mesmo trigger dos itens. Aprovação interna de desconto acima do limite, por versão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_itens', function (Blueprint $table) {
            $table->foreignId('catalogo_item_id')->nullable()->constrained('catalogo_itens')->restrictOnDelete();
            $table->bigInteger('preco_tabela_centavos')->nullable();
            $table->decimal('desconto_max_percentual', 5, 2)->nullable(); // limite aplicado (do item ou regra geral)
        });
        DB::statement('ALTER TABLE orcamento_itens ADD CONSTRAINT itens_catalogo_coerente CHECK ((catalogo_item_id IS NULL AND preco_tabela_centavos IS NULL) OR (catalogo_item_id IS NOT NULL AND preco_tabela_centavos >= 0 AND desconto_max_percentual IS NOT NULL))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION orcamento_itens_congelados() RETURNS trigger AS $$
            DECLARE st text;
            BEGIN
                SELECT status INTO st FROM orcamentos WHERE id = COALESCE(NEW.orcamento_id, OLD.orcamento_id);
                IF st <> 'rascunho' THEN
                    IF TG_OP = 'UPDATE' AND NEW.descricao = OLD.descricao AND NEW.quantidade = OLD.quantidade
                       AND NEW.preco_unitario_centavos = OLD.preco_unitario_centavos AND NEW.total_centavos = OLD.total_centavos
                       AND NEW.orcamento_id = OLD.orcamento_id AND NEW.extra = OLD.extra
                       AND NEW.catalogo_item_id IS NOT DISTINCT FROM OLD.catalogo_item_id
                       AND NEW.preco_tabela_centavos IS NOT DISTINCT FROM OLD.preco_tabela_centavos
                       AND NEW.desconto_max_percentual IS NOT DISTINCT FROM OLD.desconto_max_percentual THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'itens de orçamento enviado são imutáveis';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Schema::create('aprovacoes_internas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orcamento_id')->constrained('orcamentos')->restrictOnDelete();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->unsignedSmallInteger('versao');
            // pendente → aprovada | recusada; cancelada quando o rascunho muda (o pedido deixa de corresponder ao conteúdo).
            $table->string('status', 12)->default('pendente');
            $table->char('assinatura', 64); // hash do conteúdo do rascunho avaliado
            $table->bigInteger('subtotal_centavos');
            $table->bigInteger('desconto_centavos');
            $table->bigInteger('total_centavos');
            $table->decimal('maior_desconto_percentual', 7, 2);
            $table->jsonb('excessos'); // [{descricao, desconto_percentual, limite_percentual}]
            $table->text('motivo')->nullable();
            $table->foreignId('solicitado_por')->constrained('users')->restrictOnDelete();
            $table->timestampTz('solicitado_em');
            $table->foreignId('decidido_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decidido_em')->nullable();
            $table->text('justificativa')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'unidade_id']);
        });
        DB::statement("ALTER TABLE aprovacoes_internas ADD CONSTRAINT aprovacoes_internas_valores CHECK (status IN ('pendente','aprovada','recusada','cancelada') AND (status NOT IN ('aprovada','recusada') OR (decidido_por IS NOT NULL AND decidido_em IS NOT NULL AND justificativa IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX aprovacoes_internas_uma_pendente ON aprovacoes_internas (orcamento_id) WHERE status = 'pendente'");
    }

    public function down(): void
    {
        Schema::dropIfExists('aprovacoes_internas');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION orcamento_itens_congelados() RETURNS trigger AS $$
            DECLARE st text;
            BEGIN
                SELECT status INTO st FROM orcamentos WHERE id = COALESCE(NEW.orcamento_id, OLD.orcamento_id);
                IF st <> 'rascunho' THEN
                    IF TG_OP = 'UPDATE' AND NEW.descricao = OLD.descricao AND NEW.quantidade = OLD.quantidade
                       AND NEW.preco_unitario_centavos = OLD.preco_unitario_centavos AND NEW.total_centavos = OLD.total_centavos
                       AND NEW.orcamento_id = OLD.orcamento_id AND NEW.extra = OLD.extra THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'itens de orçamento enviado são imutáveis';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        Schema::table('orcamento_itens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalogo_item_id');
            $table->dropColumn(['preco_tabela_centavos', 'desconto_max_percentual']);
        });
        DB::statement('ALTER TABLE orcamento_itens DROP CONSTRAINT IF EXISTS itens_catalogo_coerente');
    }
};
