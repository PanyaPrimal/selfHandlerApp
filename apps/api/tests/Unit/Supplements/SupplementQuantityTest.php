<?php

namespace Tests\Unit\Supplements;

use App\ValueObjects\SupplementQuantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplementQuantityTest extends TestCase
{
    #[DataProvider('validConversions')]
    public function test_it_converts_display_input_to_exact_canonical_quantity(
        string $value,
        string $displayUnit,
        string $stockUnit,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            SupplementQuantity::fromDisplay($value, $displayUnit, $stockUnit)->canonical(),
        );
    }

    /** @return array<string, array{string,string,string,string}> */
    public static function validConversions(): array
    {
        return [
            '500 mg' => ['500', 'mg', 'gram', '0.500000'],
            'one eighth gram' => ['0.125', 'g', 'gram', '0.125000'],
            'liquid' => ['7.5', 'ml', 'millilitre', '7.500000'],
            'pieces' => ['2', 'piece', 'piece', '2.000000'],
            'signed correction' => ['-250', 'mg', 'gram', '-0.250000'],
        ];
    }

    #[DataProvider('invalidConversions')]
    public function test_it_rejects_incompatible_or_inexact_values(
        string $value,
        string $displayUnit,
        string $stockUnit,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        SupplementQuantity::fromDisplay($value, $displayUnit, $stockUnit);
    }

    /** @return array<string, array{string,string,string}> */
    public static function invalidConversions(): array
    {
        return [
            'float notation' => ['1e3', 'mg', 'gram'],
            'too precise' => ['0.0000001', 'g', 'gram'],
            'fractional piece' => ['1.5', 'piece', 'piece'],
            'weight as volume' => ['1', 'g', 'millilitre'],
            'unknown unit' => ['1', 'oz', 'gram'],
        ];
    }
}
