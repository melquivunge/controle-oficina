<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aviso de serviço ao colaborador pelo WhatsApp (opcional, desligado por padrão; o próprio colaborador liga em /conta).
 * O aviso usa o outbox existente (message_deliveries) com o tipo novo aviso_colaborador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('receber_avisos_whatsapp')->default(false));
        DB::statement('ALTER TABLE message_deliveries DROP CONSTRAINT mensagens_valores');
        DB::statement("ALTER TABLE message_deliveries ADD CONSTRAINT mensagens_valores CHECK (tipo IN ('entrada','orcamento','cobranca','conclusao','aviso_colaborador') AND status IN ('pendente','enviado','falhou'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM message_deliveries WHERE tipo = 'aviso_colaborador'");
        DB::statement('ALTER TABLE message_deliveries DROP CONSTRAINT mensagens_valores');
        DB::statement("ALTER TABLE message_deliveries ADD CONSTRAINT mensagens_valores CHECK (tipo IN ('entrada','orcamento','cobranca','conclusao') AND status IN ('pendente','enviado','falhou'))");
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('receber_avisos_whatsapp'));
    }
};
