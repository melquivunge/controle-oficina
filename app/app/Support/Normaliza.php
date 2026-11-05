<?php

namespace App\Support;

class Normaliza
{
    public const REGEX_PLACA = '/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/'; // antiga (ABC1234) e Mercosul (ABC1D23)

    public static function placa(?string $valor): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $valor));
    }

    public static function telefone(?string $valor): string
    {
        $d = preg_replace('/\D/', '', (string) $valor);

        return (strlen($d) > 11 && str_starts_with($d, '55')) ? substr($d, 2) : $d;
    }

    public static function telefoneExibicao(?string $d): string
    {
        return match (strlen((string) $d)) {
            11 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)),
            10 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)),
            default => (string) $d,
        };
    }

    public static function placaExibicao(?string $p): string
    {
        return preg_match('/^[A-Z]{3}[0-9]{4}$/', (string) $p) ? substr($p, 0, 3).'-'.substr($p, 3) : (string) $p;
    }
}
