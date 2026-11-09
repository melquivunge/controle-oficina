<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dois tipos de nota (09/11/2026): serviço → NFS-e; peça/produto → NF-e. Cada item é serviço ou produto;
 * a OS pode ter uma NFS-e e uma NF-e ativas (uma de cada). Existentes viram serviço/NFS-e (comportamento anterior).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogo_itens', function (Blueprint $t) {
            $t->string('tipo', 10)->default('servico');
            $t->string('ncm', 8)->nullable();
        });
        Schema::table('orcamento_itens', fn (Blueprint $t) => $t->string('tipo', 10)->default('servico'));
        Schema::table('fiscal_documents', fn (Blueprint $t) => $t->string('tipo', 10)->default('nfse'));
        Schema::table('empresas', fn (Blueprint $t) => $t->jsonb('fiscal')->nullable());
        DB::statement("ALTER TABLE catalogo_itens ADD CONSTRAINT catalogo_tipo CHECK (tipo IN ('servico','produto'))");
        DB::statement("ALTER TABLE orcamento_itens ADD CONSTRAINT orcamento_item_tipo CHECK (tipo IN ('servico','produto'))");
        DB::statement("ALTER TABLE fiscal_documents ADD CONSTRAINT fiscal_tipo CHECK (tipo IN ('nfse','nfe'))");
        DB::statement('DROP INDEX IF EXISTS fiscal_um_ativo');
        DB::statement("CREATE UNIQUE INDEX fiscal_um_ativo_por_tipo ON fiscal_documents (ordem_id, tipo) WHERE status IN ('pendente','processando','autorizado')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fiscal_um_ativo_por_tipo');
        DB::statement("CREATE UNIQUE INDEX fiscal_um_ativo ON fiscal_documents (ordem_id) WHERE status IN ('pendente','processando','autorizado')");
        DB::statement('ALTER TABLE fiscal_documents DROP CONSTRAINT IF EXISTS fiscal_tipo');
        DB::statement('ALTER TABLE orcamento_itens DROP CONSTRAINT IF EXISTS orcamento_item_tipo');
        DB::statement('ALTER TABLE catalogo_itens DROP CONSTRAINT IF EXISTS catalogo_tipo');
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn('fiscal'));
        Schema::table('fiscal_documents', fn (Blueprint $t) => $t->dropColumn('tipo'));
        Schema::table('orcamento_itens', fn (Blueprint $t) => $t->dropColumn('tipo'));
        Schema::table('catalogo_itens', fn (Blueprint $t) => $t->dropColumn(['tipo', 'ncm']));
    }
};
