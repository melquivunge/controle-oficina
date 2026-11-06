<?php

namespace App\Integracoes\Placa;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fonte NÃO OFICIAL (06/11/2026, escolha do usuário): serviço de terceiro que repassa dados do SINESP Cidadão
 * (http, sem contrato/SLA; dados podem estar desatualizados). Só a placa sai do sistema; só marca/modelo/ano/cor são usados.
 * Falha ou demora = null (a recepção digita). Resultado guardado 30 dias por placa.
 */
class IntegrasistPlaca implements ConsultaPlaca
{
    /** Às vezes vem "MARCA/MODELO" (ou "I/TOYOTA HILUX ..." para importado) nos dois campos: separa. */
    public static function marcaModelo(string $marca, string $modelo): array
    {
        $marca = trim($marca);
        $modelo = trim($modelo);
        if ($marca === '' || ($marca !== $modelo && ! str_contains($marca, '/'))) {
            return [$marca, $modelo];
        }
        $s = preg_replace('#^I/#i', '', $marca); // I/ = importado
        $partes = str_contains($s, '/') ? explode('/', $s, 2) : (preg_split('/\s+/', $s, 2) ?: [$s]);

        return [trim($partes[0]), trim($partes[1] ?? '') ?: ($marca !== $modelo ? $modelo : '')];
    }

    public function consultar(string $placa): ?array
    {
        if (! preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placa)) {
            return null;
        }

        return Cache::remember("placa:integrasist:{$placa}", now()->addDays(30), function () use ($placa) {
            $r = Http::timeout(6)->connectTimeout(3)->acceptJson()->get(rtrim((string) config('oficina.placa.url'), '/').'/sinesp/placa/'.$placa);
            $d = $r->successful() ? $r->json() : null;
            if (! is_array($d) || (string) ($d['codigoRetorno'] ?? '') !== '0' || strtoupper((string) ($d['placa'] ?? '')) !== $placa) {
                Log::info('consulta de placa sem resultado', ['status' => $r->status(), 'codigo' => is_array($d) ? ($d['codigoRetorno'] ?? null) : null]);

                return null; // ponytail: null não fica no cache (remember não guarda null): tenta de novo na próxima
            }
            $texto = fn ($v, int $max) => ($v = trim((string) $v)) === '' ? null : mb_substr(mb_convert_case(mb_strtolower($v), MB_CASE_TITLE), 0, $max);
            $ano = (int) ($d['anoModelo'] ?? 0) ?: (int) ($d['ano'] ?? 0);
            [$d['marca'], $d['modelo']] = self::marcaModelo((string) ($d['marca'] ?? ''), (string) ($d['modelo'] ?? ''));

            return ['marca' => mb_substr(mb_strtoupper(trim((string) ($d['marca'] ?? ''))), 0, 40) ?: null, // marca: sigla do DETRAN (M.BENZ, VW)
                'modelo' => mb_substr(trim((string) ($d['modelo'] ?? '')), 0, 80) ?: null,
                'ano' => $ano >= 1950 && $ano <= now()->year + 1 ? $ano : null, 'cor' => $texto($d['cor'] ?? '', 30)];
        });
    }
}
