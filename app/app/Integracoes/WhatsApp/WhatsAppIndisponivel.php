<?php

namespace App\Integracoes\WhatsApp;

use App\Models\WhatsappNumero;
use Illuminate\Http\Request;
use RuntimeException;

/** Modo real sem adaptador utilizável: falha explícita, nunca silenciosa. */
class WhatsAppIndisponivel implements ProvedorWhatsApp
{
    public function __construct(private string $motivo) {}

    public function nome(): string
    {
        return 'indisponivel';
    }

    public function webhookAutentico(Request $request): bool
    {
        return false;
    }

    public function interpretar(array $payload): array
    {
        return [];
    }

    public function enviarTexto(WhatsappNumero $numero, string $para, string $texto): string
    {
        throw new RuntimeException($this->motivo);
    }

    public function enviarModelo(WhatsappNumero $numero, string $para, string $modelo, string $idioma): string
    {
        throw new RuntimeException($this->motivo);
    }

    public function enviarMidia(WhatsappNumero $numero, string $para, string $conteudo, string $mime, string $nomeArquivo, ?string $legenda): string
    {
        throw new RuntimeException($this->motivo);
    }

    public function baixarMidia(WhatsappNumero $numero, string $midiaId, int $limiteBytes): array
    {
        throw new RuntimeException($this->motivo);
    }
}
