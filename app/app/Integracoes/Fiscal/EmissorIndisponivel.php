<?php

namespace App\Integracoes\Fiscal;

use RuntimeException;

class EmissorIndisponivel implements EmissorFiscal
{
    public function nome(): string
    {
        return 'indisponivel';
    }

    public function consultar(string $chave, array $dados = []): ?array
    {
        throw new RuntimeException('Emissão fiscal real não configurada: depende de validação contábil e provedor homologado.');
    }

    public function emitir(string $chave, array $dados): array
    {
        throw new RuntimeException('Emissão fiscal real não configurada: depende de validação contábil e provedor homologado.');
    }

    public function cancelar(string $chave, string $motivo, array $dados = []): void
    {
        throw new RuntimeException('Emissão fiscal real não configurada.');
    }
}
