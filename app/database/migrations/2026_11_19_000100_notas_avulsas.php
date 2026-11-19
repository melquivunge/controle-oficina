<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nota de peças avulsa (19/11/2026): NF-e sem OS — venda ou remessa (ex.: peça vendida no Mercado Livre e enviada pelos Correios).
 * A nota fiscal passa a pertencer a uma OS (com orçamento) OU a uma nota avulsa; avulsa é sempre NF-e.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_avulsas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $t->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $t->string('operacao', 10);  // venda | remessa
            $t->string('canal', 15);     // presencial | internet | mercado_livre
            $t->jsonb('itens');          // [{descricao, quantidade, preco_unitario_centavos, total_centavos, ncm, codigo}]
            $t->bigInteger('valor_centavos');
            $t->smallInteger('frete');   // modFrete: 0 remetente, 1 destinatário, 9 sem frete
            $t->string('pagamento', 2);  // tPag
            $t->string('observacao', 500)->nullable();
            $t->foreignId('criado_por')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->index(['unidade_id', 'created_at']);
        });
        DB::statement("ALTER TABLE notas_avulsas ADD CONSTRAINT notas_avulsas_valores CHECK (operacao IN ('venda','remessa')
            AND canal IN ('presencial','internet','mercado_livre') AND frete IN (0,1,9) AND pagamento IN ('01','03','04','15','17','90','99') AND valor_centavos > 0)");

        DB::statement('ALTER TABLE fiscal_documents ALTER COLUMN ordem_id DROP NOT NULL');
        DB::statement('ALTER TABLE fiscal_documents ALTER COLUMN orcamento_id DROP NOT NULL');
        Schema::table('fiscal_documents', fn (Blueprint $t) => $t->foreignId('nota_avulsa_id')->nullable()->constrained('notas_avulsas')->restrictOnDelete());
        DB::statement("ALTER TABLE fiscal_documents ADD CONSTRAINT fiscal_origem CHECK (
            (ordem_id IS NOT NULL AND orcamento_id IS NOT NULL AND nota_avulsa_id IS NULL)
            OR (ordem_id IS NULL AND orcamento_id IS NULL AND nota_avulsa_id IS NOT NULL AND tipo = 'nfe'))");
        DB::statement("CREATE UNIQUE INDEX fiscal_avulsa_um_ativo ON fiscal_documents (nota_avulsa_id) WHERE nota_avulsa_id IS NOT NULL AND status IN ('pendente','processando','autorizado')");

        // Nota avulsa rejeitada também vira pendência resolvível (sem OS).
        DB::statement('ALTER TABLE resolucoes_pendencias ALTER COLUMN ordem_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fiscal_avulsa_um_ativo');
        DB::statement('ALTER TABLE fiscal_documents DROP CONSTRAINT IF EXISTS fiscal_origem');
        Schema::table('fiscal_documents', fn (Blueprint $t) => $t->dropConstrainedForeignId('nota_avulsa_id'));
        DB::statement('ALTER TABLE fiscal_documents ALTER COLUMN ordem_id SET NOT NULL');
        DB::statement('ALTER TABLE fiscal_documents ALTER COLUMN orcamento_id SET NOT NULL');
        DB::statement('ALTER TABLE resolucoes_pendencias ALTER COLUMN ordem_id SET NOT NULL');
        Schema::dropIfExists('notas_avulsas');
    }
};
