<?php

namespace App\ValueObjects;

use Carbon\CarbonInterface;

/**
 * The weekday vocabulary shared by routine schedules.
 *
 * The two-letter codes match the weekday names used by the long-term
 * recurrence engine, so a future rule can adopt them without a data rewrite.
 */
enum WeekdayCode: string
{
    case Monday = 'MO';
    case Tuesday = 'TU';
    case Wednesday = 'WE';
    case Thursday = 'TH';
    case Friday = 'FR';
    case Saturday = 'SA';
    case Sunday = 'SU';

    public static function fromDate(CarbonInterface $date): self
    {
        return match ($date->dayOfWeekIso) {
            1 => self::Monday,
            2 => self::Tuesday,
            3 => self::Wednesday,
            4 => self::Thursday,
            5 => self::Friday,
            6 => self::Saturday,
            default => self::Sunday,
        };
    }

    public static function normalize(mixed $code): ?self
    {
        if ($code instanceof self) {
            return $code;
        }

        return is_string($code) ? self::tryFrom(strtoupper(trim($code))) : null;
    }

    /**
     * Normalize a mixed list into unique codes in calendar order.
     *
     * @param  iterable<mixed>  $codes
     * @return list<string>
     */
    public static function normalizeList(iterable $codes): array
    {
        $normalized = [];

        foreach ($codes as $code) {
            $weekday = self::normalize($code);

            if ($weekday instanceof self) {
                $normalized[] = $weekday->value;
            }
        }

        return array_values(array_intersect(self::values(), array_unique($normalized)));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
