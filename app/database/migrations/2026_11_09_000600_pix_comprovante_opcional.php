<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Comprovante do Pix recebido no app do banco passa a ser opcional (decisão do usuário, 09/11/2026). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pagamentos DROP CONSTRAINT IF EXISTS pagamentos_pix_manual_comprovante');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pagamentos ADD CONSTRAINT pagamentos_pix_manual_comprovante CHECK (meio <> 'pix_manual' OR comprovante_caminho IS NOT NULL) NOT VALID");
    }
};
