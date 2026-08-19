<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_it_converts_brazilian_amounts_to_cents(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::toCents($input));
    }

    public static function amounts(): array
    {
        return [['25,90', 2590], ['1.234,56', 123456], ['10.00', 1000]];
    }

    public function test_it_formats_brl(): void
    {
        $this->assertSame('R$ 1.234,56', Money::format(123456));
    }
}
