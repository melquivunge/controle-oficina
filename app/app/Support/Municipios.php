<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Códigos IBGE dos municípios (resources/fiscal/municipios.csv, tabela do sistema antigo) para o endereço da NF-e. */
final class Municipios
{
    /** @var ?array<string, array{0:string,1:string}> */
    private static ?array $mapa = null;

    private static function chave(string $uf, string $nome): string
    {
        return strtoupper(trim($uf)).'|'.preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(Str::ascii(trim($nome))));
    }

    /** @return ?array{0:string,1:string} [código IBGE, nome oficial] */
    public static function buscar(string $uf, string $nome): ?array
    {
        if (self::$mapa === null) {
            self::$mapa = [];
            foreach (file(resource_path('fiscal/municipios.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                [$u, $n, $c] = explode(';', $l);
                self::$mapa[self::chave($u, $n)] = [$c, $n];
            }
        }

        return self::$mapa[self::chave($uf, $nome)] ?? null;
    }

    public static function codigo(string $uf, string $nome): ?string
    {
        return self::buscar($uf, $nome)[0] ?? null;
    }
}
