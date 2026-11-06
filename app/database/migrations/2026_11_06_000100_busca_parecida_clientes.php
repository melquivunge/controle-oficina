<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Busca de cliente tolerante a erro humano (06/11/2026): sem acento, fora de ordem, letra trocada e grafia parecida
 * (Lukas/Lucas, Thiago/Tiago, Luiz/Luis). pg_trgm e unaccent são extensões "trusted": o dono do banco pode criá-las.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        // ponytail: IMMUTABLE com unaccent explícito (dicionário fixo); sem índice: varre clientes (milhares = ms). Índice GIN em oficina_chave_nome(nome) se crescer.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION oficina_chave_nome(t text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
                SELECT regexp_replace(translate(replace(public.unaccent('public.unaccent'::regdictionary, lower(coalesce(t, ''))), 'h', ''), 'kwyz', 'cvis'), '(.)\1+', '\1', 'g')
            $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS oficina_chave_nome(text)');
    }
};
