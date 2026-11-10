<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Provedor Drope (QR Code, não oficial) para números de WhatsApp. */
return new class extends Migration
{
    private function restricao(string $provedores): void
    {
        DB::statement('ALTER TABLE whatsapp_numeros DROP CONSTRAINT whatsapp_numeros_valores');
        DB::statement("ALTER TABLE whatsapp_numeros ADD CONSTRAINT whatsapp_numeros_valores CHECK (provedor IN ({$provedores}) AND modo_conexao IN ('api','coexistencia')
            AND minutos_alerta BETWEEN 1 AND 1440 AND telefone ~ '^[0-9]{10,15}$' AND (provedor <> 'meta' OR phone_number_id IS NOT NULL))");
    }

    public function up(): void
    {
        $this->restricao("'simulado','meta','drope'");
    }

    public function down(): void
    {
        DB::table('whatsapp_numeros')->where('provedor', 'drope')->update(['provedor' => 'simulado']);
        $this->restricao("'simulado','meta'");
    }
};
