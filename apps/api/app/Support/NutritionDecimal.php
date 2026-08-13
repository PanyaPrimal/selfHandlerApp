<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Fixed-scale decimal operations for accepted nutrition facts.
 *
 * Nutrition values cross JSON, DECIMAL columns, and aggregate queries. Keeping
 * calculations as decimal strings avoids binary-float drift before the one
 * documented half-up rounding step at a persistence/response boundary.
 */
final class NutritionDecimal
{
    public static function add(int|float|string $left, int|float|string $right, int $scale): string
    {
        return bcadd(self::value($left), self::value($right), $scale);
    }

    public static function multiply(int|float|string $left, int|float|string $right, int $scale): string
    {
        $workingScale = $scale + 8;

        return self::round(bcmul(self::value($left), self::value($right), $workingScale), $scale);
    }

    public static function divide(int|float|string $numerator, int|float|string $denominator, int $scale): string
    {
        $denominator = self::value($denominator);
        if (bccomp($denominator, '0', $scale + 8) === 0) {
            throw new InvalidArgumentException('Cannot divide a nutrition decimal by zero.');
        }

        return self::round(bcdiv(self::value($numerator), $denominator, $scale + 1), $scale);
    }

    public static function format(int|float|string $value, int $scale): string
    {
        return self::round(self::value($value), $scale);
    }

    private static function round(string $value, int $scale): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('A decimal scale cannot be negative.');
        }

        $increment = $scale === 0 ? '0.5' : '0.'.str_repeat('0', $scale).'5';
        $adjusted = bccomp($value, '0', $scale + 8) < 0
            ? bcsub($value, $increment, $scale + 8)
            : bcadd($value, $increment, $scale + 8);

        return bcadd($adjusted, '0', $scale);
    }

    private static function value(int|float|string $value): string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Nutrition decimals must be finite.');
            }

            $value = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
        }

        $value = (string) $value;
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('Invalid nutrition decimal.');
        }

        return $value;
    }
}
