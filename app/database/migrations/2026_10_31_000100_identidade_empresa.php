<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identidade da empresa (registro único) usada na OS impressa; cada unidade pode ter CNPJ, telefone e endereço próprios.
 * Logo fica em disco privado (caminho aqui), servida só por rota autenticada.
 */
return new class extends Migration
{
    private const ENDERECO = ['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];

    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('razao_social', 150)->nullable();
            $table->string('nome_fantasia', 120)->nullable();
            $table->string('cnpj', 14)->nullable(); // só dígitos/letras (CNPJ alfanumérico), sem máscara
            $table->string('telefone', 11)->nullable();
            $table->string('email')->nullable();
            $this->endereco($table);
            $table->string('logo_caminho')->nullable();
            $table->string('cor_principal', 7)->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE empresas ADD CONSTRAINT empresas_cor_valida CHECK (cor_principal IS NULL OR cor_principal ~ '^#[0-9a-f]{6}$')");

        Schema::table('unidades', function (Blueprint $table) {
            $table->string('cnpj', 14)->nullable();
            $table->string('telefone', 11)->nullable();
            $this->endereco($table);
        });
    }

    private function endereco(Blueprint $table): void
    {
        $table->string('cep', 8)->nullable();
        $table->string('logradouro', 150)->nullable();
        $table->string('numero', 20)->nullable();
        $table->string('complemento', 80)->nullable();
        $table->string('bairro', 80)->nullable();
        $table->string('cidade', 80)->nullable();
        $table->string('uf', 2)->nullable();
    }

    public function down(): void
    {
        Schema::table('unidades', fn (Blueprint $table) => $table->dropColumn(array_merge(['cnpj', 'telefone'], self::ENDERECO)));
        Schema::dropIfExists('empresas');
    }
};
