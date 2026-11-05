<?php

namespace App\Support;

/** TOTP RFC 6238 (SHA-1, 30 s, 6 dígitos), compatível com aplicativos autenticadores. */
class Totp
{
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function novoSegredo(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function codigo(string $segredoBase32, int $instante, int $digitos = 6): string
    {
        $contador = pack('J', intdiv($instante, 30));
        $hash = hash_hmac('sha1', $contador, self::deBase32($segredoBase32), true);
        $offset = ord($hash[19]) & 0xF;
        $valor = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($valor % (10 ** $digitos)), $digitos, '0', STR_PAD_LEFT);
    }

    /** Aceita ±30 s de desvio de relógio. */
    public static function verificar(string $segredoBase32, string $codigo, ?int $instante = null): bool
    {
        return self::passo($segredoBase32, $codigo, $instante) !== null;
    }

    /** Passo de 30 s correspondente ao código, ou null. Usado para rejeitar reutilização. */
    public static function passo(string $segredoBase32, string $codigo, ?int $instante = null): ?int
    {
        $codigo = preg_replace('/\D/', '', $codigo);
        if (strlen($codigo) !== 6) {
            return null;
        }
        $instante ??= time();
        foreach ([-30, 0, 30] as $desvio) {
            if (hash_equals(self::codigo($segredoBase32, $instante + $desvio), $codigo)) {
                return intdiv($instante + $desvio, 30);
            }
        }

        return null;
    }

    public static function uri(string $segredo, string $conta): string
    {
        return 'otpauth://totp/'.rawurlencode('Controle de Oficina:'.$conta).'?secret='.$segredo.'&issuer='.rawurlencode('Controle de Oficina');
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $saida = '';
        foreach (str_split($bits, 5) as $bloco) {
            $saida .= self::ALFABETO[bindec(str_pad($bloco, 5, '0'))];
        }

        return $saida;
    }

    public static function deBase32(string $texto): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($texto, '='))) as $c) {
            $pos = strpos(self::ALFABETO, $c);
            if ($pos === false) {
                throw new \InvalidArgumentException('Segredo base32 inválido.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $saida = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $saida .= chr(bindec($byte));
            }
        }

        return $saida;
    }
}
