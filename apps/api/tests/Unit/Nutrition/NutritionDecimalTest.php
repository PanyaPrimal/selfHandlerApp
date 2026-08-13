<?php

namespace Tests\Unit\Nutrition;

use App\Support\NutritionDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NutritionDecimalTest extends TestCase
{
    public function test_fixed_scale_operations_round_half_up_without_float_drift(): void
    {
        $this->assertSame('0.30', NutritionDecimal::add('0.10', '0.20', 2));
        $this->assertSame('33.333', NutritionDecimal::divide('100', '3', 3));
        $this->assertSame('2.35', NutritionDecimal::format('2.345', 2));
        $this->assertSame('-2.35', NutritionDecimal::format('-2.345', 2));
        $this->assertSame('0.020000', NutritionDecimal::multiply('0.1', '0.2', 6));
    }

    public function test_division_by_zero_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NutritionDecimal::divide('1', '0', 3);
    }
}
