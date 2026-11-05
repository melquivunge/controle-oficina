<?php

namespace App\Integracoes\WhatsApp;

use App\Models\WhatsappNumero;
use Illuminate\Http\Request;

/**
 * Contrato da Central de WhatsApp. Implementações: SimuladorWhatsApp (demo/testes) e MetaCloudApi
 * (WhatsApp Cloud API oficial — NÃO homologado com conta real). Um parceiro oficial (BSP) implementa o mesmo contrato.
 *
 * Eventos normalizados devolvidos por interpretar():
 *   ['evento_id' => string único no provedor, 'tipo' => mensagem|eco|historico|status, 'numero_id' => int, 'dados' => array]
 *   mensagem/eco/historico: dados = [id, contato (wa_id), nome?, direcao (entrada|saida), tipo, texto?, midia? [id, mime, nome], localizacao? [lat, lng, nome], timestamp]
 *   status: dados = [id, status (enviada|entregue|lida|falhou), timestamp, erro?]
 */
interface ProvedorWhatsApp
{
    public function nome(): string;

    /** Autenticidade do webhook (assinatura). Falso = recusar sem gravar nada. */
    public function webhookAutentico(Request $request): bool;

    /** @return list<array{evento_id: string, tipo: string, numero_id: int, dados: array}> */
    public function interpretar(array $payload): array;

    /** Envia texto livre; retorna o id da mensagem no provedor. Lança exceção em falha (aceito ≠ entregue). */
    public function enviarTexto(WhatsappNumero $numero, string $para, string $texto): string;

    public function enviarModelo(WhatsappNumero $numero, string $para, string $modelo, string $idioma): string;

    public function enviarMidia(WhatsappNumero $numero, string $para, string $conteudo, string $mime, string $nomeArquivo, ?string $legenda): string;

    /** @return array{conteudo: string, mime: ?string} */
    public function baixarMidia(WhatsappNumero $numero, string $midiaId, int $limiteBytes): array;
}
