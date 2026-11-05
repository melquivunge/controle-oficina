<?php

namespace Tests\Unit;

use App\Support\Dinheiro;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DinheiroTest extends TestCase
{
    public function test_converte_texto_brl_para_centavos(): void
    {
        $this->assertSame(18000, Dinheiro::deTexto('180'));
        $this->assertSame(18050, Dinheiro::deTexto('180,5'));
        $this->assertSame(123456, Dinheiro::deTexto('R$ 1.234,56'));
        $this->assertSame(123400, Dinheiro::deTexto('1.234'));
        $this->assertSame(1999, Dinheiro::deTexto('19.99'));
        $this->assertSame(10, Dinheiro::deTexto('0,10'));
    }

    public function test_rejeita_valores_invalidos(): void
    {
        foreach (['', 'abc', '-5', '1,234', '1.2.3,4'] as $v) {
            try {
                Dinheiro::deTexto($v);
                $this->fail("deveria rejeitar {$v}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_quantidade_decimal_exata(): void
    {
        $this->assertSame('2.500', Dinheiro::quantidade('2,5'));
        $this->assertSame('0.333', Dinheiro::quantidade('0.333'));
        $this->expectException(InvalidArgumentException::class);
        Dinheiro::quantidade('1,2345');
    }

    public function test_quantidade_zero_e_rejeitada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Dinheiro::quantidade('0');
    }

    public function test_total_do_item_arredonda_meio_para_cima_sem_float(): void
    {
        $this->assertSame(36000, Dinheiro::totalItem('2.000', 18000));
        $this->assertSame(8333, Dinheiro::totalItem('2.500', 3333));  // 8332,5 → 8333
        $this->assertSame(33, Dinheiro::totalItem('0.333', 100));     // 33,3 → 33
        $this->assertSame(34, Dinheiro::totalItem('0.335', 100));     // 33,5 → 34
        $this->assertSame(30, Dinheiro::totalItem('0.300', 100));     // 0,1+0,2 clássico de float
        $this->assertSame(99999999900, Dinheiro::totalItem('999999999.000', 100)); // sem perda em valores grandes
    }

    public function test_formatacao(): void
    {
        $this->assertSame('R$ 1.234,56', Dinheiro::formatar(123456));
        $this->assertSame('R$ 0,05', Dinheiro::formatar(5));
        $this->assertSame('2,5', Dinheiro::quantidadeExibicao('2.500'));
        $this->assertSame('3', Dinheiro::quantidadeExibicao('3.000'));
    }
}
