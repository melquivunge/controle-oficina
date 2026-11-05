<?php

namespace App\Integracoes\Pix;

use App\Models\Cobranca;
use App\Models\Pagamento;
use Illuminate\Http\Request;
use RuntimeException;

/** Modo real sem adaptador homologado: falha explícita, nunca sucesso simulado. */
class ProvedorIndisponivel implements ProvedorPix
{
    public function nome(): string
    {
        return 'indisponivel';
    }

    public function criarCobranca(Cobranca $cobranca, string $conta): array
    {
        throw new RuntimeException('Integração Pix real não configurada. Escolha e homologue um provedor.');
    }

    public function consultar(string $txid): array
    {
        throw new RuntimeException('Integração Pix real não configurada.');
    }

    public function cancelar(string $txid): void
    {
        throw new RuntimeException('Integração Pix real não configurada.');
    }

    public function devolver(Pagamento $pagamento, int $valorCentavos, string $chaveIdempotencia): array
    {
        throw new RuntimeException('Integração Pix real não configurada: devolução não enviada ao banco. Devolva por outro meio e registre a resolução.');
    }

    public function webhookAutentico(Request $request): bool
    {
        return false;
    }
}
