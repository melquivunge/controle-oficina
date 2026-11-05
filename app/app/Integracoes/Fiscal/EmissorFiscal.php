<?php

namespace App\Integracoes\Fiscal;

/** Emissor fiscal. Real só após validação contábil e homologação. */
interface EmissorFiscal
{
    public function nome(): string;

    /** Consulta resultado anterior pela chave (evita emissão dupla). @return ?array{status:string, numero:?string, protocolo:?string, mensagem:?string, documento:?string} */
    public function consultar(string $chave): ?array;

    /** @return array{status:string, numero:?string, protocolo:?string, mensagem:?string, documento:?string} */
    public function emitir(string $chave, array $dados): array;

    public function cancelar(string $chave, string $motivo): void;
}
