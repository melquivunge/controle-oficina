<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CNPJ com dígitos verificadores válidos. Aceita o formato numérico e o alfanumérico (IN RFB 2.229/2024, a partir de 07/2026):
 * 12 posições [0-9A-Z] + 2 dígitos verificadores numéricos; cada caractere vale (código ASCII − 48).
 */
class Cnpj implements ValidationRule
{
    public static function normalizar(?string $valor): string
    {
        return preg_replace('/[^0-9A-Z]/', '', mb_strtoupper((string) $valor));
    }

    public static function valido(?string $valor): bool
    {
        $c = self::normalizar($valor);
        if (! preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $c) || preg_match('/^(\d)\1{13}$/', $c)) {
            return false;
        }
        foreach ([12, 13] as $n) {
            $soma = 0;
            $peso = 2;
            for ($i = $n - 1; $i >= 0; $i--) {
                $soma += (ord($c[$i]) - 48) * $peso;
                $peso = $peso === 9 ? 2 : $peso + 1;
            }
            $dv = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
            if ((int) $c[$n] !== $dv) {
                return false;
            }
        }

        return true;
    }

    public static function exibicao(?string $valor): string
    {
        $c = (string) $valor;

        return strlen($c) === 14 ? sprintf('%s.%s.%s/%s-%s', substr($c, 0, 2), substr($c, 2, 3), substr($c, 5, 3), substr($c, 8, 4), substr($c, 12)) : $c;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valido(is_string($value) ? $value : null)) {
            $fail('CNPJ inválido: confira os dígitos.');
        }
    }
}
