<?php

namespace App\Console\Commands;

use App\Models\Cobranca;
use App\Services\Financeiro;
use Illuminate\Console\Command;

/** Conciliação periódica: recupera notificações perdidas, conclui cobranças em criação e expira vencidas. */
class ConciliarPix extends Command
{
    protected $signature = 'oficina:conciliar-pix';

    protected $description = 'Consulta o provedor Pix para cobranças abertas (recupera webhooks perdidos).';

    public function handle(Financeiro $financeiro): int
    {
        $res = [];
        foreach (Cobranca::where('status', 'criando')->where('created_at', '<', now()->subMinute())->get() as $c) {
            $res[] = 'criando→'.$financeiro->registrarNoProvedor($c)->status;
        }
        foreach (Cobranca::whereIn('status', ['pendente', 'cancelada', 'expirada'])->whereNotNull('txid')->where('updated_at', '>', now()->subDays(3))->pluck('txid') as $txid) {
            try {
                $res[] = $financeiro->conciliarCobranca($txid);
            } catch (\Throwable $e) {
                report($e);
                $res[] = 'erro';
            }
        }
        $this->info('conciliação: '.json_encode(array_count_values($res)));

        return self::SUCCESS;
    }
}
