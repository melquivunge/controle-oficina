<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('veiculos', fn (Blueprint $t) => $t->string('marca', 40)->nullable()->after('placa'));
    }

    public function down(): void
    {
        Schema::table('veiculos', fn (Blueprint $t) => $t->dropColumn('marca'));
    }
};
