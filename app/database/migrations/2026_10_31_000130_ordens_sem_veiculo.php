<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entrada sem veículo/placa (cliente traz só o jogo de rodas ou a peça): veiculo_id pode ser nulo
 * somente com a descrição do item preenchida (garantido no banco).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens', function (Blueprint $table) {
            $table->string('item_descricao', 200)->nullable();
        });
        DB::statement('ALTER TABLE ordens ALTER COLUMN veiculo_id DROP NOT NULL');
        DB::statement("ALTER TABLE ordens ADD CONSTRAINT ordens_veiculo_ou_item CHECK (veiculo_id IS NOT NULL OR (item_descricao IS NOT NULL AND btrim(item_descricao) <> ''))");
        // Conferência física: OS sem veículo ausente gera divergência pelo número da OS, sem placa.
        DB::statement('ALTER TABLE divergencias ALTER COLUMN placa DROP NOT NULL');
        DB::statement("ALTER TABLE divergencias ADD CONSTRAINT divergencias_placa_ou_ordem CHECK (placa IS NOT NULL OR ordem_id IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE divergencias DROP CONSTRAINT IF EXISTS divergencias_placa_ou_ordem');
        DB::statement('ALTER TABLE ordens DROP CONSTRAINT IF EXISTS ordens_veiculo_ou_item');
        Schema::table('ordens', fn (Blueprint $table) => $table->dropColumn('item_descricao'));
        // veiculo_id/placa continuam aceitando nulo: voltar a NOT NULL exigiria apagar entradas sem veículo.
    }
};
