<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pós-venda: problema no serviço e negociação fora do padrão são pendências resolvíveis.
 * Acrescenta os tipos ao CHECK existente de resolucoes_pendencias SEM perder tipos incluídos por outras migrations
 * (lê a definição atual em vez de reescrever a lista).
 */
return new class extends Migration
{
    private const TIPOS = ['pos_venda_problema', 'pos_venda_negociacao'];

    public function up(): void
    {
        $this->recriar(array_values(array_unique([...$this->tiposAtuais(), ...self::TIPOS])));
    }

    public function down(): void
    {
        DB::table('resolucoes_pendencias')->whereIn('tipo', self::TIPOS)->delete();
        $this->recriar(array_values(array_diff($this->tiposAtuais(), self::TIPOS)));
    }

    private function tiposAtuais(): array
    {
        $def = DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'resolucoes_tipo' AND conrelid = 'resolucoes_pendencias'::regclass")?->d ?? '';
        preg_match_all("/'([a-z0-9_]+)'/", $def, $m);

        return $m[1];
    }

    private function recriar(array $tipos): void
    {
        $lista = implode(',', array_map(fn ($t) => "'".preg_replace('/[^a-z0-9_]/', '', $t)."'", $tipos));
        DB::statement('ALTER TABLE resolucoes_pendencias DROP CONSTRAINT IF EXISTS resolucoes_tipo');
        DB::statement("ALTER TABLE resolucoes_pendencias ADD CONSTRAINT resolucoes_tipo CHECK (tipo IN ({$lista}))");
    }
};
