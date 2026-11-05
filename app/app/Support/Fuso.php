<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Instantes armazenados em UTC; apresentação em America/Sao_Paulo. */
class Fuso
{
    public const LOCAL = 'America/Sao_Paulo';

    public static function exibir(?DateTimeInterface $instante, string $formato = 'd/m/Y H:i'): string
    {
        return $instante ? CarbonImmutable::instance($instante)->setTimezone(self::LOCAL)->format($formato) : '—';
    }

    /** Início do dia local convertido para UTC. */
    public static function inicioDoDia(string $data): CarbonImmutable
    {
        return CarbonImmutable::parse($data, self::LOCAL)->startOfDay()->utc();
    }
}
