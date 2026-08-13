<?php

namespace Tests\Unit\Finance;

use App\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('canonicalValues')]
    public function test_it_canonicalizes_exact_amount_and_currency(string $input, string $expected): void
    {
        $money = Money::of($input, 'uah');

        $this->assertSame($expected, $money->amount());
        $this->assertSame('UAH', $money->currency());
        $this->assertSame($expected.' UAH', (string) $money);
    }

    /** @return array<string, array{string,string}> */
    public static function canonicalValues(): array
    {
        return [
            'zero' => ['0', '0.0000'],
            'smallest' => ['0.0001', '0.0001'],
            'padding' => ['10.125', '10.1250'],
            'negative' => ['-123.4567', '-123.4567'],
            'leading sign' => ['+1.2', '1.2000'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_it_rejects_inexact_or_unsafe_values(string $amount, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of($amount, $currency);
    }

    /** @return array<string, array{string,string}> */
    public static function invalidValues(): array
    {
        return [
            'exponent' => ['1e3', 'UAH'],
            'too precise' => ['0.00001', 'UAH'],
            'not a number' => ['NaN', 'UAH'],
            'too large' => ['1000000000000000', 'UAH'],
            'bad currency' => ['1', 'US'],
        ];
    }

    public function test_arithmetic_negation_comparison_and_half_up_fx_are_exact(): void
    {
        $left = Money::of('0.1000', 'USD');
        $right = Money::of('0.2000', 'USD');

        $this->assertSame('0.3000', $left->add($right)->amount());
        $this->assertSame('-0.1000', $left->negate()->amount());
        $this->assertTrue($left->lessThan($right));
        $this->assertTrue(Money::of('0', 'USD')->isZero());
        $this->assertSame('12.3457', Money::of('10', 'USD')->convert('EUR', '1.234567')->amount());
    }

    public function test_arithmetic_refuses_mixed_currencies_and_division_by_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1', 'USD')->add(Money::of('1', 'EUR'));
    }
}
