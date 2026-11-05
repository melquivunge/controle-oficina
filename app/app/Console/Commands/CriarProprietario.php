<?php

namespace App\Console\Commands;

use App\Enums\Papel;
use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Primeiro acesso: cria a conta do proprietário. Explícito; nunca executado no startup. */
class CriarProprietario extends Command
{
    protected $signature = 'oficina:criar-proprietario {--nome=} {--email=}';

    protected $description = 'Cria a conta de proprietário (primeiro acesso). A senha é pedida de forma interativa.';

    public function handle(): int
    {
        $nome = $this->option('nome') ?: $this->ask('Nome completo');
        $email = mb_strtolower($this->option('email') ?: $this->ask('E-mail'));
        // Sem terminal (ex.: automação), a senha vem do stdin, nunca de argumento visível em `ps`.
        $senha = $this->input->isInteractive() && stream_isatty(STDIN)
            ? $this->secret('Senha (mínimo 10 caracteres, letras e números)')
            : trim((string) fgets(STDIN));

        $v = Validator::make(compact('nome', 'email', 'senha'), [
            'nome' => ['required', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'senha' => ['required', Password::min(10)->letters()->numbers()],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($nome, $email, $senha) {
            $user = new User(['name' => $nome, 'email' => $email, 'password' => $senha]);
            $user->papel = Papel::Proprietario;
            $user->save();
            Auditoria::registrar('usuario_criado', $user, depois: $user->only('name', 'email', 'papel'), motivo: 'criado por comando de console', atorTipo: 'sistema');

            return $user;
        });
        $this->call('oficina:redefinir-dois-fatores', ['email' => $email, '--motivo' => 'primeiro acesso do proprietário']);
        $this->info("Proprietário {$email} criado.");

        return self::SUCCESS;
    }
}
