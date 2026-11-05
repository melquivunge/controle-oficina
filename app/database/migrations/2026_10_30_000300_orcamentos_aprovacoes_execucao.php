<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->unsignedSmallInteger('versao');
            // rascunho → enviado → aprovado|recusado; aprovado → substituido quando outra versão é aprovada
            $table->string('status', 20)->default('rascunho');
            $table->bigInteger('subtotal_centavos')->default(0);
            $table->bigInteger('desconto_centavos')->default(0);
            $table->bigInteger('total_centavos')->default(0);
            $table->text('desconto_motivo')->nullable();
            $table->text('observacao_cliente')->nullable();
            $table->foreignId('criado_por')->constrained('users')->restrictOnDelete();
            $table->timestampTz('enviado_em')->nullable();
            $table->timestampsTz();
            $table->unique(['ordem_id', 'versao']);
        });
        DB::statement("ALTER TABLE orcamentos ADD CONSTRAINT orcamentos_status_valido CHECK (status IN ('rascunho','enviado','aprovado','recusado','substituido'))");
        DB::statement('ALTER TABLE orcamentos ADD CONSTRAINT orcamentos_valores CHECK (desconto_centavos >= 0 AND desconto_centavos <= subtotal_centavos AND total_centavos = subtotal_centavos - desconto_centavos)');
        // No máximo um rascunho, uma versão aguardando decisão e uma aprovada vigente por ordem.
        DB::statement("CREATE UNIQUE INDEX orcamentos_um_rascunho ON orcamentos (ordem_id) WHERE status = 'rascunho'");
        DB::statement("CREATE UNIQUE INDEX orcamentos_um_enviado ON orcamentos (ordem_id) WHERE status = 'enviado'");
        DB::statement("CREATE UNIQUE INDEX orcamentos_um_aprovado ON orcamentos (ordem_id) WHERE status = 'aprovado'");

        Schema::create('orcamento_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orcamento_id')->constrained('orcamentos')->restrictOnDelete();
            $table->unsignedSmallInteger('posicao');
            $table->string('descricao', 300);
            $table->decimal('quantidade', 12, 3);
            $table->bigInteger('preco_unitario_centavos');
            $table->bigInteger('total_centavos');
            $table->boolean('extra')->default(false); // incluído durante a execução
            $table->timestampTz('concluido_em')->nullable();
            $table->foreignId('concluido_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE orcamento_itens ADD CONSTRAINT itens_valores CHECK (quantidade > 0 AND preco_unitario_centavos >= 0 AND total_centavos >= 0)');

        // Itens congelados: fora do rascunho só é permitido registrar conclusão (execução).
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
            CREATE TRIGGER orcamento_itens_congelados BEFORE INSERT OR UPDATE OR DELETE ON orcamento_itens
                FOR EACH ROW EXECUTE FUNCTION orcamento_itens_congelados();
        SQL);

        Schema::create('aprovacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orcamento_id')->unique()->constrained('orcamentos')->restrictOnDelete(); // uma decisão por versão
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->string('decisao', 10);
            $table->unsignedSmallInteger('versao');
            $table->bigInteger('total_centavos');
            $table->string('canal', 20); // link_cliente | presencial
            $table->foreignId('registrado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('observacao')->nullable();
            $table->timestampTz('decidido_em');
        });
        DB::statement("ALTER TABLE aprovacoes ADD CONSTRAINT aprovacoes_valores CHECK (decisao IN ('aprovado','recusado') AND canal IN ('link_cliente','presencial') AND (canal = 'link_cliente' OR registrado_por IS NOT NULL))");

        Schema::create('links_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_id')->constrained('ordens')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expira_em');
            $table->timestampTz('revogado_em')->nullable();
            $table->foreignId('criado_por')->constrained('users')->restrictOnDelete();
            $table->timestampTz('ultimo_acesso_em')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links_cliente');
        Schema::dropIfExists('aprovacoes');
        Schema::dropIfExists('orcamento_itens');
        DB::unprepared('DROP FUNCTION IF EXISTS orcamento_itens_congelados()');
        Schema::dropIfExists('orcamentos');
    }
};
