<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tema único (12/11/2026): a escolha pessoal deixa de existir. Se a empresa não tinha tema padrão,
 * herda o tema que o proprietário escolheu para si (o mais recente), para a cor escolhida não se perder.
 */
return new class extends Migration
{
    public function up(): void
    {
        $empresa = DB::table('empresas')->orderBy('id')->first();
        if ($empresa && ! $empresa->tema_padrao) {
            $tema = DB::table('users')->where('papel', 'proprietario')->where('ativo', true)->whereNotNull('tema')->orderByDesc('updated_at')->value('tema');
            if ($tema) {
                DB::table('empresas')->where('id', $empresa->id)->update(['tema_padrao' => $tema]);
            }
        }
        DB::table('users')->whereNotNull('tema')->update(['tema' => null]); // ponytail: coluna fica sem uso; remover numa limpeza futura
    }

    public function down(): void {}
};
