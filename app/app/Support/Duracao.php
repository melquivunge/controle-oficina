<?php

namespace App\Support;

/** Duração curta em pt-BR para esperas: "8 min", "2 h 5 min", "1 d 3 h". */
class Duracao
{
    public static function curta(int $minutos): string
    {
        $minutos = max(0, $minutos);
        if ($minutos < 60) {
            return $minutos.' min';
        }
        if ($minutos < 1440) {
            return intdiv($minutos, 60).' h'.($minutos % 60 ? ' '.($minutos % 60).' min' : '');
        }

        return intdiv($minutos, 1440).' d'.(intdiv($minutos % 1440, 60) ? ' '.intdiv($minutos % 1440, 60).' h' : '');
    }
}
