<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emissão fiscal real, etapa 2 (09/11/2026): certificado A1 criptografado, numeração atômica por
 * tipo/ambiente/série e os dados que a Fazenda devolve (chave de acesso e XML).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificados_digitais', function (Blueprint $t) {
            $t->id();
            $t->text('pfx');   // conteúdo do .pfx, criptografado pela APP_KEY (cast encrypted)
            $t->text('senha'); // criptografada
            $t->string('documento', 14); // CNPJ (ou CPF) do titular
            $t->string('titular', 200);
            $t->timestampTz('valido_de');
            $t->timestampTz('valido_ate');
            $t->foreignId('enviado_por')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });

        Schema::create('fiscal_numeracao', function (Blueprint $t) {
            $t->string('tipo', 10);
            $t->unsignedSmallInteger('ambiente'); // 1 produção, 2 homologação
            $t->string('serie', 5);
            $t->unsignedBigInteger('ultimo');
            $t->primary(['tipo', 'ambiente', 'serie']);
        });

        Schema::table('fiscal_documents', function (Blueprint $t) {
            $t->unsignedSmallInteger('ambiente')->nullable();
            $t->string('serie', 5)->nullable();
            $t->unsignedBigInteger('numero_dps')->nullable(); // nDPS (NFS-e) ou nNF (NF-e): fixado antes do envio
            $t->string('chave_acesso', 60)->nullable();
            $t->string('xml', 255)->nullable(); // XML autorizado, disco privado
        });
        DB::statement('CREATE UNIQUE INDEX fiscal_numero_unico ON fiscal_documents (tipo, ambiente, serie, numero_dps) WHERE numero_dps IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fiscal_numero_unico');
        Schema::table('fiscal_documents', fn (Blueprint $t) => $t->dropColumn(['ambiente', 'serie', 'numero_dps', 'chave_acesso', 'xml']));
        Schema::dropIfExists('fiscal_numeracao');
        Schema::dropIfExists('certificados_digitais');
    }
};
