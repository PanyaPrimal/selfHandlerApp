<?php

namespace App\ValueObjects;

use App\Services\SafePaceValidator;

/**
 * The measurable body metrics and everything that is true about each one.
 *
 * This is the single place a canonical unit, a display unit, a precision or a
 * plausible bound is written down, so adding a metric is one case here and no
 * migration: the value column is already canonical decimal and the metric column
 * is already validated against this enum.
 *
 * Canonical units follow `docs/design/data-conventions.md` section 6 — mass in
 * grams, lengths in metres — and are what crosses the API. Converting for
 * display is the client's job.
 */
enum BodyMetric: string
{
    case BodyMass = 'body_mass';
    case BodyFatPercentage = 'body_fat_percentage';
    case Waist = 'waist';
    case Chest = 'chest';
    case Hips = 'hips';
    case Thigh = 'thigh';
    case UpperArm = 'upper_arm';
    case Neck = 'neck';
    case Calf = 'calf';

    public function label(): string
    {
        return match ($this) {
            self::BodyMass => 'Body mass',
            self::BodyFatPercentage => 'Body fat',
            self::Waist => 'Waist',
            self::Chest => 'Chest',
            self::Hips => 'Hips',
            self::Thigh => 'Thigh',
            self::UpperArm => 'Upper arm',
            self::Neck => 'Neck',
            self::Calf => 'Calf',
        };
    }

    public function canonicalUnit(): string
    {
        return match ($this) {
            self::BodyMass => 'gram',
            self::BodyFatPercentage => 'percent',
            default => 'metre',
        };
    }

    /**
     * @return array{metric: string, imperial: string}
     */
    public function displayUnits(): array
    {
        return match ($this->canonicalUnit()) {
            'gram' => ['metric' => 'kg', 'imperial' => 'lb'],
            'percent' => ['metric' => '%', 'imperial' => '%'],
            default => ['metric' => 'cm', 'imperial' => 'in'],
        };
    }

    /** Lowest plausible value, in the canonical unit. */
    public function minimum(): string
    {
        return match ($this) {
            self::BodyMass => '20000',
            self::BodyFatPercentage => '2',
            self::Waist, self::Chest, self::Hips => '0.30',
            self::Thigh => '0.10',
            self::UpperArm, self::Calf => '0.10',
            self::Neck => '0.15',
        };
    }

    /** Highest plausible value, in the canonical unit. */
    public function maximum(): string
    {
        return match ($this) {
            self::BodyMass => '500000',
            self::BodyFatPercentage => '75',
            self::Waist, self::Chest, self::Hips => '3.00',
            self::Thigh => '1.50',
            self::UpperArm, self::Calf, self::Neck => '1.00',
        };
    }

    /**
     * The fastest weekly change this application will accept without saying
     * something, in the canonical unit, or `null` where no boundary is known.
     *
     * Only body mass has one. For loss it is the CDC's published "gradual,
     * steady" upper bound of 2 pounds a week; for gain no comparable authority
     * publishes a rate, so the application applies its own conservative limit
     * and labels it as such. Nothing is invented for the remaining metrics.
     *
     * @see SafePaceValidator
     */
    public function hasPaceBoundary(): bool
    {
        return $this === self::BodyMass;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The vocabulary as the API exposes it.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return array_map(static fn (self $metric): array => [
            'value' => $metric->value,
            'label' => $metric->label(),
            'canonical_unit' => $metric->canonicalUnit(),
            'display_unit' => $metric->displayUnits(),
            'minimum' => $metric->minimum(),
            'maximum' => $metric->maximum(),
        ], self::cases());
    }
}
