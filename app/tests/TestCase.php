<?php

namespace Tests;

use App\Enums\Papel;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Login já com 2FA validado na sessão (fluxo do 2FA tem testes próprios). */
    protected function entrarComo(User $user): static
    {
        return $this->actingAs($user)->withSession(['dois_fatores_ok' => $user->id, 'auth.password_confirmed_at' => time()]);
    }

    /** PNG 1×1 real (dispensa a extensão GD só para testes). */
    protected function foto(string $nome = 'foto.png'): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\UploadedFile::fake()->createWithContent($nome, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    }

    protected function usuario(Papel $papel, ?Unidade $unidade = null): User
    {
        return User::factory()->papel($papel, $unidade)->create();
    }
}
