<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_money_uses_decimal_half_up_rounding(): void
    {
        $this->assertSame('0.30', Money::add('0.1', '0.2'));
        $this->assertSame('0.01', Money::percent('0.50', '1'));
        $this->assertSame('300.00', Money::percent('10000', '3'));
        $this->assertSame('1.01', Money::round('1.005'));
        $this->assertSame('2.000', Money::quantity('0.750', '1.250'));
    }

    public function test_multiplication_rounds_each_sale_line_consistently(): void
    {
        $this->assertSame('10.03', Money::mul('8.02', '1.250'));
    }
}
