<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Chassi (VIN) opcional do veículo (pedido da oficina, 10/11/2026). Único quando informado; a placa continua obrigatória. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('veiculos', fn (Blueprint $t) => $t->string('chassi', 17)->nullable()->unique()->after('placa'));
    }

    public function down(): void
    {
        Schema::table('veiculos', fn (Blueprint $t) => $t->dropColumn('chassi'));
    }
};
