<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_a_decimal_amount_becomes_whole_nanodollars_and_back(): void
    {
        $this->assertSame(46_125_000, Money::fromDecimal('0.046125000'));
        $this->assertSame('0.046125000', Money::toDecimal(46_125_000));
        $this->assertSame(5_000_000_000, Money::fromDecimal('5'));
        $this->assertSame('12.000000001', Money::toDecimal(12_000_000_001));
        $this->assertSame('-0.500000000', Money::toDecimal(-500_000_000));
    }

    public function test_an_amount_finer_than_a_nanodollar_is_refused_rather_than_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('0.0000000001');
    }
}
