<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** IE do cliente (19/11/2026): destinatário contribuinte de ICMS — a SEFAZ recusa a NF-e sem ela (232). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', fn (Blueprint $t) => $t->string('inscricao_estadual', 14)->nullable());
    }

    public function down(): void
    {
        Schema::table('clientes', fn (Blueprint $t) => $t->dropColumn('inscricao_estadual'));
    }
};
