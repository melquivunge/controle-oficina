<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comprovante de pagamento (09/11/2026): 'pix_manual' = Pix recebido no app do banco, conferido no extrato, com comprovante
 * obrigatório (o Pix automático continua sendo 'pix', confirmado pelo provedor). Cartão pode anexar o recibo da maquininha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->string('comprovante_caminho', 255)->nullable();
            $t->string('comprovante_mime', 100)->nullable();
        });
        DB::statement('ALTER TABLE pagamentos DROP CONSTRAINT pagamentos_valores');
        DB::statement("ALTER TABLE pagamentos ADD CONSTRAINT pagamentos_valores CHECK (valor_centavos > 0 AND meio IN ('pix','pix_manual','dinheiro','cartao_externo') AND status IN ('confirmado','registrado','em_revisao'))");
        DB::statement("ALTER TABLE pagamentos ADD CONSTRAINT pagamentos_pix_manual_comprovante CHECK (meio <> 'pix_manual' OR comprovante_caminho IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pagamentos DROP CONSTRAINT IF EXISTS pagamentos_pix_manual_comprovante');
        DB::statement('ALTER TABLE pagamentos DROP CONSTRAINT pagamentos_valores');
        DB::statement("ALTER TABLE pagamentos ADD CONSTRAINT pagamentos_valores CHECK (valor_centavos > 0 AND meio IN ('pix','dinheiro','cartao_externo') AND status IN ('confirmado','registrado','em_revisao'))");
        Schema::table('pagamentos', fn (Blueprint $t) => $t->dropColumn(['comprovante_caminho', 'comprovante_mime']));
    }
};
