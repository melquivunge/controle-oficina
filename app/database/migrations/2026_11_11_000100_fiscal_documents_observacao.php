<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Observação digitada na emissão (pedido do usuário, 11/11/2026): NF-e em infCpl; NFS-e no fim de xDescServ. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', fn (Blueprint $t) => $t->string('observacao', 500)->nullable());
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', fn (Blueprint $t) => $t->dropColumn('observacao'));
    }
};
