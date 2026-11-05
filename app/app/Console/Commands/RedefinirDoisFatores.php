<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auditoria;
use App\Support\Totp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Recuperação com acesso ao servidor: gera nova chave de 2FA e a mostra uma única vez no terminal. */
class RedefinirDoisFatores extends Command
{
    protected $signature = 'oficina:redefinir-dois-fatores {email} {--motivo=}';

    protected $description = 'Gera nova chave de verificação em duas etapas para um proprietário.';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower($this->argument('email')))->first();
        if (! $user?->exigeDoisFatores()) {
            $this->error('Conta não encontrada ou não exige verificação em duas etapas.');

            return self::FAILURE;
        }
        $motivo = $this->option('motivo') ?: 'redefinição pelo console';
        $segredo = Totp::novoSegredo();
        DB::transaction(function () use ($user, $segredo, $motivo) {
            $user->forceFill(['two_factor_secret' => $segredo, 'two_factor_confirmed_at' => now()])->save();
            Auditoria::registrar('dois_fatores_chave_gerada', $user, motivo: $motivo, atorTipo: 'sistema');
        });
        $this->line('Adicione esta chave no aplicativo autenticador (mostrada só agora):');
        $this->line('  '.trim(chunk_split($segredo, 4, ' ')));
        $this->line('  '.Totp::uri($segredo, $user->email));

        return self::SUCCESS;
    }
}
