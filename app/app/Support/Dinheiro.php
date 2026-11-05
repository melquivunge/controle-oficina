<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Valores em centavos (int) e quantidades decimais exatas (string, até 3 casas). Sem float.
 * Regra de arredondamento: total do item = quantidade × preço unitário, arredondado meio-para-cima ao centavo.
 */
class Dinheiro
{
    /** "1.234,56" | "180" | "180,5" | "180.50" → centavos. */
    public static function deTexto(string $texto): int
    {
        $t = preg_replace('/[^\d,.\-]/', '', trim($texto));
        if (str_contains($t, ',')) {
            if (! preg_match('/^(\d+|\d{1,3}(\.\d{3})+),\d{1,2}$/', $t)) {
                throw new InvalidArgumentException('Valor inválido.');
            }
            $t = str_replace('.', '', $t);
            $t = str_replace(',', '.', $t);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $t)) {
            $t = str_replace('.', '', $t); // 1.234 = mil duzentos e trinta e quatro
        }
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $t)) {
            throw new InvalidArgumentException('Valor inválido.');
        }
        [$reais, $cent] = array_pad(explode('.', $t), 2, '0');

        return (int) $reais * 100 + (int) str_pad($cent, 2, '0');
    }

    /** "2,5" → "2.500". Até 3 casas, maior que zero. */
    public static function quantidade(string $texto): string
    {
        $t = str_replace(',', '.', trim($texto));
        if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', $t) || bccomp($t, '0', 3) <= 0) {
            throw new InvalidArgumentException('Quantidade inválida.');
        }

        return bcadd($t, '0', 3);
    }

    public static function totalItem(string $quantidade, int $precoCentavos): int
    {
        $bruto = bcmul($quantidade, (string) $precoCentavos, 3);

        return (int) bcadd($bruto, '0.5', 0); // valores não negativos: truncar após +0,5 = meio-para-cima
    }

    public static function formatar(int $centavos): string
    {
        $sinal = $centavos < 0 ? '-' : '';
        $c = abs($centavos);

        return $sinal.'R$ '.number_format(intdiv($c, 100), 0, ',', '.').','.str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function paraCampo(int $centavos): string
    {
        return intdiv($centavos, 100).','.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function quantidadeExibicao(string $q): string
    {
        return str_replace('.', ',', rtrim(rtrim($q, '0'), '.'));
    }
}
