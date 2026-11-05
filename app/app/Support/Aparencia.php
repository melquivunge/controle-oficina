<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Tema;
use App\Models\User;

/**
 * Resolve o tema da tela: escolha do usuário > padrão da empresa > claro.
 * Saída vai para <html data-tema="claro|escuro|sistema" style="--marca…">; as cores do tema personalizado
 * vêm com a cor de texto calculada (preto ou branco, o de maior contraste WCAG) para nunca ficar ilegível.
 */
final class Aparencia
{
    public const PADROES = ['claro' => 'Claro', 'escuro' => 'Escuro', 'sistema' => 'Seguir o aparelho'];

    /** @return array{modo: string, estilo: string, chave: string} */
    public static function resolver(?User $usuario = null, ?Empresa $empresa = null): array
    {
        $empresa ??= Empresa::atual();
        foreach ([$usuario?->tema, $empresa->tema_padrao] as $chave) {
            if ($chave && ($r = self::deChave($chave))) {
                return $r;
            }
        }

        return ['modo' => 'claro', 'estilo' => '', 'chave' => 'claro'];
    }

    /** Opções válidas para os seletores (padrões + personalizados). @return array<string, string> */
    public static function opcoes(): array
    {
        return self::PADROES + Tema::orderBy('nome')->get()->mapWithKeys(fn (Tema $t) => [$t->chave() => $t->nome])->all();
    }

    private static function deChave(string $chave): ?array
    {
        if (isset(self::PADROES[$chave])) {
            return ['modo' => $chave, 'estilo' => '', 'chave' => $chave];
        }
        $tema = str_starts_with($chave, 'tema:') ? Tema::find((int) substr($chave, 5)) : null;

        return $tema ? ['modo' => $tema->base, 'estilo' => self::estilo($tema), 'chave' => $chave] : null;
    }

    public static function estilo(Tema $tema): string
    {
        // Cores validadas como #rrggbb na entrada e no CHECK do banco; seguras dentro de style="".
        return sprintf('--marca:%s;--marca-contraste:%s;--menu-fundo:%s;--menu-texto:%s',
            $tema->cor_marca, self::textoSobre($tema->cor_marca), $tema->cor_menu, self::textoSobre($tema->cor_menu));
    }

    /** Branco ou preto, o que tiver maior contraste sobre a cor (sempre ≥ 4,58:1, AA). */
    public static function textoSobre(string $hex): string
    {
        return self::contraste($hex, '#ffffff') >= self::contraste($hex, '#000000') ? '#ffffff' : '#000000';
    }

    public static function contraste(string $a, string $b): float
    {
        [$x, $y] = [self::luminancia($a), self::luminancia($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private static function luminancia(string $hex): float
    {
        $c = array_map(function ($v) {
            $v = hexdec($v) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}
