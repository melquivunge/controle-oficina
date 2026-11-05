<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OS enviada ao cliente por WhatsApp (tipo documento_os) pelo outbox oficial de mensagens.
 * Lê a definição atual do CHECK mensagens_valores (tipos e status) e acrescenta só o novo tipo; down remove só ele.
 */
return new class extends Migration
{
    private const TIPO = 'documento_os';

    public function up(): void
    {
        [$tipos, $status] = $this->atuais();
        $this->recriar(array_values(array_unique([...$tipos, self::TIPO])), $status);
    }

    public function down(): void
    {
        DB::table('message_deliveries')->where('tipo', self::TIPO)->delete();
        [$tipos, $status] = $this->atuais();
        $this->recriar(array_values(array_diff($tipos, [self::TIPO])), $status);
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function atuais(): array
    {
        $def = DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'mensagens_valores' AND conrelid = 'message_deliveries'::regclass")?->d ?? '';
        $partes = preg_split('/\bstatus\b/', $def, 2);
        preg_match_all("/'([a-z0-9_]+)'/", $partes[0] ?? '', $t);
        preg_match_all("/'([a-z0-9_]+)'/", $partes[1] ?? '', $s);
        if (! $t[1] || ! $s[1]) {
            throw new RuntimeException('CHECK mensagens_valores não encontrado ou em formato inesperado.');
        }

        return [$t[1], $s[1]];
    }

    private function recriar(array $tipos, array $status): void
    {
        $lista = fn (array $v) => implode(',', array_map(fn ($x) => "'".preg_replace('/[^a-z0-9_]/', '', $x)."'", $v));
        DB::statement('ALTER TABLE message_deliveries DROP CONSTRAINT IF EXISTS mensagens_valores');
        DB::statement('ALTER TABLE message_deliveries ADD CONSTRAINT mensagens_valores CHECK (tipo IN ('.$lista($tipos).') AND status IN ('.$lista($status).'))');
    }
};
