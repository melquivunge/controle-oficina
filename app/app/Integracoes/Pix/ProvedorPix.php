<?php

namespace App\Integracoes\Pix;

use App\Models\Cobranca;
use App\Models\Pagamento;
use Illuminate\Http\Request;

/**
 * Contrato do adaptador Pix. Implementações: SimuladorPix (sem dinheiro real).
 * O adaptador real depende da escolha e homologação do provedor.
 */
interface ProvedorPix
{
    public function nome(): string;

    /** Cria (ou recupera, pela chave idempotente) a cobrança no provedor. @return array{txid:string, copia_e_cola:string, expira_em:\DateTimeInterface} */
    public function criarCobranca(Cobranca $cobranca, string $conta): array;

    /** Estado autoritativo no provedor. @return array{status:string, conta:string, moeda:string, valor_pago_centavos:?int, e2e_id:?string, pago_em:?\DateTimeInterface} */
    public function consultar(string $txid): array;

    public function cancelar(string $txid): void;

    /**
     * Solicita a devolução (total ou parcial) de um Pix recebido. A mesma chave idempotente nunca gera
     * uma segunda devolução no provedor: repetir a chamada devolve o mesmo resultado/identificador.
     * Recusa do provedor vem como status 'recusada' com mensagem; falha técnica lança exceção.
     *
     * @return array{status: 'concluida'|'processando'|'recusada', id_provedor: ?string, mensagem: ?string}
     */
    public function devolver(Pagamento $pagamento, int $valorCentavos, string $chaveIdempotencia): array;

    /** Autentica a notificação conforme o protocolo do provedor. */
    public function webhookAutentico(Request $request): bool;
}
