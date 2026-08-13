<?php

namespace App\ValueObjects;

use App\Support\NutritionDecimal;
use InvalidArgumentException;

final readonly class SupplementQuantity
{
    public const STOCK_GRAM = 'gram';

    public const STOCK_MILLILITRE = 'millilitre';

    public const STOCK_PIECE = 'piece';

    public const STOCK_UNITS = [self::STOCK_GRAM, self::STOCK_MILLILITRE, self::STOCK_PIECE];

    public const DISPLAY_UNITS = ['mg', 'g', 'ml', 'piece'];

    private function __construct(private string $canonical) {}

    public static function fromDisplay(int|string $value, string $displayUnit, string $stockUnit): self
    {
        $value = (string) $value;
        if (! in_array($stockUnit, self::STOCK_UNITS, true)
            || ! in_array($displayUnit, self::DISPLAY_UNITS, true)
            || ! self::compatible($displayUnit, $stockUnit)) {
            throw new InvalidArgumentException('The supplement quantity unit is incompatible.');
        }

        $scale = $displayUnit === 'mg' ? 3 : 6;
        if (! preg_match('/^-?\d+(?:\.\d{1,'.$scale.'})?$/', $value)) {
            throw new InvalidArgumentException('The supplement quantity is not an exact supported decimal.');
        }

        if ($displayUnit === 'piece' && str_contains($value, '.')) {
            throw new InvalidArgumentException('A piece quantity must be a whole number.');
        }

        $canonical = $displayUnit === 'mg'
            ? NutritionDecimal::divide($value, '1000', 6)
            : NutritionDecimal::format($value, 6);

        return new self($canonical);
    }

    public static function fromCanonical(int|string $value, string $stockUnit): self
    {
        $display = match ($stockUnit) {
            self::STOCK_GRAM => 'g',
            self::STOCK_MILLILITRE => 'ml',
            self::STOCK_PIECE => 'piece',
            default => throw new InvalidArgumentException('Unknown supplement stock unit.'),
        };

        return self::fromDisplay($value, $display, $stockUnit);
    }

    public static function compatible(string $displayUnit, string $stockUnit): bool
    {
        return match ($stockUnit) {
            self::STOCK_GRAM => in_array($displayUnit, ['mg', 'g'], true),
            self::STOCK_MILLILITRE => $displayUnit === 'ml',
            self::STOCK_PIECE => $displayUnit === 'piece',
            default => false,
        };
    }

    public function canonical(): string
    {
        return $this->canonical;
    }
}
