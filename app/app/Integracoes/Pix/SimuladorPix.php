<?php

namespace App\Integracoes\Pix;

use App\Models\Cobranca;
use App\Models\Pagamento;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Provedor Pix SIMULADO: nenhum valor real é cobrado ou movimentado. */
class SimuladorPix implements ProvedorPix
{
    public function nome(): string
    {
        return 'simulado';
    }

    public function criarCobranca(Cobranca $cobranca, string $conta): array
    {
        // Idempotente: a mesma chave devolve a mesma cobrança.
        $txid = 'SIM'.substr(hash('sha256', $cobranca->chave_idempotencia), 0, 29);
        $expira = CarbonImmutable::now()->addMinutes(config('oficina.pix.validade_minutos'));
        DB::table('simulador_pix')->insertOrIgnore(['txid' => $txid, 'conta' => $conta, 'valor_centavos' => $cobranca->valor_centavos, 'status' => 'ativa', 'expira_em' => $expira]);
        $linha = DB::table('simulador_pix')->where('txid', $txid)->first();

        return [
            'txid' => $txid,
            'copia_e_cola' => 'SIMULACAO-SEM-VALOR|'.$txid.'|'.$cobranca->valor_centavos,
            'expira_em' => CarbonImmutable::parse($linha->expira_em),
        ];
    }

    public function consultar(string $txid): array
    {
        $l = DB::table('simulador_pix')->where('txid', $txid)->first() ?? throw new RuntimeException('Cobrança inexistente no provedor.');
        $status = $l->status === 'ativa' && CarbonImmutable::parse($l->expira_em)->isPast() ? 'expirada' : $l->status;

        return ['status' => $status, 'conta' => $l->conta, 'moeda' => 'BRL', 'valor_pago_centavos' => $l->valor_pago_centavos !== null ? (int) $l->valor_pago_centavos : null,
            'e2e_id' => $l->e2e_id, 'pago_em' => $l->pago_em ? CarbonImmutable::parse($l->pago_em) : null];
    }

    public function cancelar(string $txid): void
    {
        DB::table('simulador_pix')->where('txid', $txid)->where('status', 'ativa')->update(['status' => 'cancelada']);
    }

    /**
     * Devolução SIMULADA: nada é enviado a banco algum. Determinística e idempotente pela chave
     * (uma linha por chave; repetir não duplica). Recusa explícita com config oficina.pix.simular_falha_devolucao
     * ou quando o valor excede o pago menos o já devolvido.
     */
    public function devolver(Pagamento $pagamento, int $valorCentavos, string $chaveIdempotencia): array
    {
        return DB::transaction(function () use ($pagamento, $valorCentavos, $chaveIdempotencia) {
            $existente = DB::table('simulador_pix_devolucoes')->where('chave_idempotencia', $chaveIdempotencia)->lockForUpdate()->first();
            if ($existente?->status === 'devolvida') {
                return ['status' => 'concluida', 'id_provedor' => $existente->id_devolucao, 'mensagem' => null];
            }
            $cobranca = $pagamento->id_provedor ? DB::table('simulador_pix')->where('e2e_id', $pagamento->id_provedor)->lockForUpdate()->first() : null;
            $jaDevolvido = (int) DB::table('simulador_pix_devolucoes')->where('e2e_id', (string) $pagamento->id_provedor)
                ->where('status', 'devolvida')->where('chave_idempotencia', '<>', $chaveIdempotencia)->sum('valor_centavos');
            $recusa = match (true) {
                (bool) config('oficina.pix.simular_falha_devolucao') => 'Provedor recusou a devolução (simulação): saldo insuficiente na conta recebedora.',
                ! $cobranca => 'Provedor recusou a devolução: pagamento não encontrado no provedor.',
                $valorCentavos <= 0 || $jaDevolvido + $valorCentavos > (int) $cobranca->valor_pago_centavos => 'Provedor recusou a devolução: valor excede o disponível para devolução.',
                default => null,
            };
            $linha = ['e2e_id' => (string) $pagamento->id_provedor, 'valor_centavos' => $valorCentavos, 'status' => $recusa ? 'recusada' : 'devolvida',
                'id_devolucao' => $recusa ? null : 'D'.strtoupper(substr(hash('sha256', $chaveIdempotencia), 0, 31)), 'mensagem' => $recusa, 'atualizado_em' => now()];
            if ($existente) {
                DB::table('simulador_pix_devolucoes')->where('chave_idempotencia', $chaveIdempotencia)->update($linha + ['tentativas' => $existente->tentativas + 1]);
            } else {
                DB::table('simulador_pix_devolucoes')->insert($linha + ['chave_idempotencia' => $chaveIdempotencia]);
            }

            return $recusa ? ['status' => 'recusada', 'id_provedor' => null, 'mensagem' => $recusa] : ['status' => 'concluida', 'id_provedor' => $linha['id_devolucao'], 'mensagem' => null];
        });
    }

    public function webhookAutentico(Request $request): bool
    {
        $segredo = (string) config('oficina.pix.webhook_segredo');

        return $segredo !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $segredo), (string) $request->header('X-Assinatura'));
    }

    /** Somente simulação: o "cliente" paga. Valor diferente permite testar divergências. Pagar cobrança cancelada simula atraso do banco. */
    public function simularPagamento(string $txid, ?int $valorCentavos = null): array
    {
        return DB::transaction(function () use ($txid, $valorCentavos) {
            $l = DB::table('simulador_pix')->where('txid', $txid)->lockForUpdate()->first() ?? throw new RuntimeException('Cobrança inexistente.');
            if ($l->status === 'paga') {
                return ['evento_id' => 'evt-'.$l->e2e_id, 'txid' => $txid];
            }
            $e2e = 'E'.strtoupper(Str::random(31));
            DB::table('simulador_pix')->where('txid', $txid)->update(['status' => 'paga', 'valor_pago_centavos' => $valorCentavos ?? $l->valor_centavos, 'e2e_id' => $e2e, 'pago_em' => now()]);

            return ['evento_id' => 'evt-'.$e2e, 'txid' => $txid];
        });
    }

    public function assinar(string $corpo): string
    {
        return hash_hmac('sha256', $corpo, (string) config('oficina.pix.webhook_segredo'));
    }
}
