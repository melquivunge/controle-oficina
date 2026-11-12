<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Central de WhatsApp recebe vídeo (12/11/2026): antes o Drope "videomessage" virava texto "não suportada". */
return new class extends Migration
{
    private function check(string $tipos): void
    {
        DB::statement('ALTER TABLE whatsapp_mensagens DROP CONSTRAINT whatsapp_mensagens_valores');
        DB::statement("ALTER TABLE whatsapp_mensagens ADD CONSTRAINT whatsapp_mensagens_valores CHECK (
            direcao IN ('entrada','saida') AND origem IN ('cliente','sistema','celular')
            AND tipo IN ({$tipos})
            AND status IN ('recebida','enviando','enviada','entregue','lida','falhou')
            AND (midia_status IS NULL OR midia_status IN ('pendente','baixada','rejeitada','falhou'))
            AND ((direcao = 'entrada' AND origem = 'cliente' AND status = 'recebida' AND autor_id IS NULL)
              OR (direcao = 'saida' AND origem = 'sistema' AND status <> 'recebida')
              OR (direcao = 'saida' AND origem = 'celular' AND autor_id IS NULL AND status <> 'recebida')))");
    }

    public function up(): void
    {
        $this->check("'texto','imagem','audio','video','documento','localizacao','modelo'");
    }

    public function down(): void
    {
        $this->check("'texto','imagem','audio','documento','localizacao','modelo'");
    }
};
