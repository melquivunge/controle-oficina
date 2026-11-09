<?php

namespace App\Integracoes\Fiscal;

/**
 * Emissão real (09/11/2026, etapa 2): NFS-e pela Sefin Nacional e NF-e pela SEFAZ (SVRS).
 * Ambiente 2 = homologação (sem validade fiscal); 1 = produção. Escolhido por OFICINA_FISCAL.
 */
class EmissorReal implements EmissorFiscal
{
    public function __construct(private int $ambiente) {}

    public function nome(): string
    {
        return $this->ambiente === 1 ? 'producao' : 'homologacao';
    }

    public function ambiente(): int
    {
        return $this->ambiente;
    }

    public function consultar(string $chave, array $dados = []): ?array
    {
        return ($dados['tipo'] ?? 'nfse') === 'nfse' ? (new NfseNacional($this->ambiente))->consultar($dados) : null;
    }

    public function emitir(string $chave, array $dados): array
    {
        return ($dados['tipo'] ?? 'nfse') === 'nfse'
            ? (new NfseNacional($this->ambiente))->emitir($dados)
            : (new NfeSefaz($this->ambiente))->emitir($dados + ['chave' => $chave]);
    }

    public function cancelar(string $chave, string $motivo, array $dados = []): void
    {
        ($dados['tipo'] ?? 'nfse') === 'nfse'
            ? (new NfseNacional($this->ambiente))->cancelar($dados['chave_acesso'], $motivo)
            : (new NfeSefaz($this->ambiente))->cancelar($dados['chave_acesso'], $motivo, (string) $dados['protocolo']);
    }
}
