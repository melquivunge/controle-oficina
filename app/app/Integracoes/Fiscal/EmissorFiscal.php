<?php

namespace App\Integracoes\Fiscal;

/** Emissor fiscal. Real só após validação contábil e homologação. */
interface EmissorFiscal
{
    public function nome(): string;

    /** Consulta resultado anterior pela chave (evita emissão dupla). @return ?array{status:string, numero:?string, protocolo:?string, mensagem:?string, documento:?string} */
    public function consultar(string $chave, array $dados = []): ?array;

    /** @return array{status:string, numero:?string, protocolo:?string, mensagem:?string, documento:?string, chave_acesso?:string, xml?:string, extensao?:string} */
    public function emitir(string $chave, array $dados): array;

    /** $dados: tipo, chave_acesso e protocolo da nota autorizada. */
    public function cancelar(string $chave, string $motivo, array $dados = []): void;
}
