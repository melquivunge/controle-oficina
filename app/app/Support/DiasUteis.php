<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Dias úteis da oficina: segunda a sexta, exceto os feriados nacionais de config('oficina.feriados')
 * e, quando a unidade é informada, os estaduais/municipais dela (tabela feriados_unidade). Datas no fuso local.
 */
class DiasUteis
{
    /** @return list<string> datas Y-m-d */
    public static function feriados(?int $unidadeId = null): array
    {
        $nacionais = array_map('strval', (array) config('oficina.feriados', []));
        if ($unidadeId === null) {
            return array_values($nacionais);
        }
        $locais = \App\Models\FeriadoUnidade::where('unidade_id', $unidadeId)->pluck('data')
            ->map(fn ($d) => $d instanceof DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10))->all();

        return array_values(array_unique([...$nacionais, ...$locais]));
    }

    public static function hoje(): CarbonImmutable
    {
        return CarbonImmutable::now(Fuso::LOCAL)->startOfDay();
    }

    /** @param  list<string>|null  $feriados  lista já carregada (evita consultar a cada dia em laços) */
    public static function ehUtil(DateTimeInterface $dia, ?int $unidadeId = null, ?array $feriados = null): bool
    {
        $d = CarbonImmutable::instance($dia);

        return ! $d->isWeekend() && ! in_array($d->format('Y-m-d'), $feriados ?? self::feriados($unidadeId), true);
    }

    /** Data após N dias úteis a partir de $inicio (o próprio dia de início não conta). */
    public static function somar(DateTimeInterface $inicio, int $dias, ?int $unidadeId = null): CarbonImmutable
    {
        $feriados = self::feriados($unidadeId);
        if ($dias < 1) {
            throw new \InvalidArgumentException('Informe ao menos 1 dia útil.');
        }
        $d = CarbonImmutable::parse(CarbonImmutable::instance($inicio)->format('Y-m-d'), Fuso::LOCAL);
        while ($dias > 0) {
            $d = $d->addDay();
            if (self::ehUtil($d, feriados: $feriados)) {
                $dias--;
            }
        }

        return $d;
    }

    /** Dias úteis entre $de (exclusivo) e $ate (inclusivo); 0 se $ate não é posterior. */
    public static function entre(DateTimeInterface $de, DateTimeInterface $ate, ?int $unidadeId = null): int
    {
        $feriados = self::feriados($unidadeId);
        $d = CarbonImmutable::parse(CarbonImmutable::instance($de)->format('Y-m-d'), Fuso::LOCAL);
        $fim = CarbonImmutable::instance($ate)->format('Y-m-d');
        $n = 0;
        while ($d->format('Y-m-d') < $fim) {
            $d = $d->addDay();
            $n += self::ehUtil($d, feriados: $feriados) ? 1 : 0;
        }

        return $n;
    }
}
