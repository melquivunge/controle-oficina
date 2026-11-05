<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aparência: temas personalizados (criados pelo proprietário), tema padrão da empresa e escolha individual do usuário.
 * Tema guardado como texto: 'claro', 'escuro', 'sistema' ou 'tema:{id}'. Referência apagada volta ao padrão (App\Support\Aparencia).
 * Também nome do sistema e descrição da empresa (menu, aba do navegador e tela de entrada).
 */
return new class extends Migration
{
    private const TEMA = '^(claro|escuro|sistema|tema:[0-9]+)$';

    public function up(): void
    {
        Schema::create('temas', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 40)->unique();
            $table->string('base', 6); // claro|escuro
            $table->string('cor_marca', 7);
            $table->string('cor_menu', 7);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE temas ADD CONSTRAINT temas_valores CHECK (base IN ('claro','escuro') AND cor_marca ~ '^#[0-9a-f]{6}$' AND cor_menu ~ '^#[0-9a-f]{6}$')");

        Schema::table('empresas', function (Blueprint $table) {
            $table->string('nome_sistema', 60)->nullable();
            $table->string('descricao', 300)->nullable();
            $table->string('tema_padrao', 20)->nullable();
        });
        DB::statement("ALTER TABLE empresas ADD CONSTRAINT empresas_tema_valido CHECK (tema_padrao IS NULL OR tema_padrao ~ '".self::TEMA."')");

        Schema::table('users', fn (Blueprint $table) => $table->string('tema', 20)->nullable());
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_tema_valido CHECK (tema IS NULL OR tema ~ '".self::TEMA."')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_tema_valido');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('tema'));
        DB::statement('ALTER TABLE empresas DROP CONSTRAINT IF EXISTS empresas_tema_valido');
        Schema::table('empresas', fn (Blueprint $table) => $table->dropColumn(['nome_sistema', 'descricao', 'tema_padrao']));
        Schema::dropIfExists('temas');
    }
};
