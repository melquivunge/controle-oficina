<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    // Vetores RFC 6238 (SHA-1, segredo ASCII "12345678901234567890"), 6 dígitos finais.
    public function test_vetores_rfc6238(): void
    {
        $segredo = Totp::base32('12345678901234567890');
        $this->assertSame('287082', Totp::codigo($segredo, 59));
        $this->assertSame('081804', Totp::codigo($segredo, 1111111109));
        $this->assertSame('050471', Totp::codigo($segredo, 1111111111));
        $this->assertSame('005924', Totp::codigo($segredo, 1234567890));
        $this->assertSame('279037', Totp::codigo($segredo, 2000000000));
    }

    public function test_verificar_aceita_janela_e_rejeita_fora(): void
    {
        $segredo = Totp::novoSegredo();
        $agora = 1_800_000_000;
        $this->assertTrue(Totp::verificar($segredo, Totp::codigo($segredo, $agora - 30), $agora));
        $this->assertFalse(Totp::verificar($segredo, Totp::codigo($segredo, $agora - 120), $agora));
        $this->assertFalse(Totp::verificar($segredo, 'abc', $agora));
        $this->assertSame('12345678901234567890', Totp::deBase32(Totp::base32('12345678901234567890')));
    }
}
