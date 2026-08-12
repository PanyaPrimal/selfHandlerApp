<?php

namespace App\Services;

use App\ValueObjects\BodyMetric;
use Carbon\CarbonImmutable;

/**
 * Tells the user when a target implies a very fast change.
 *
 * Two rules, and only two, because only two are defensible:
 *
 * - **Losing body mass.** The U.S. Centers for Disease Control and Prevention
 *   publishes that "people who lose weight at a gradual, steady pace — about 1 to
 *   2 pounds a week — are more likely to keep the weight off". The upper bound of
 *   that range, 2 lb, is 907.1847 g, and that is the boundary used here.
 *   https://www.cdc.gov/healthy-weight-growth/losing-weight/index.html
 * - **Gaining body mass.** No comparable authority publishes a general weekly
 *   rate, so this application applies its own conservative limit of 500 g a week
 *   and says so in the message rather than presenting it as guidance.
 *
 * Every other metric has neither, and nothing is invented for it.
 *
 * The result is always a warning. It never blocks the save, never edits the
 * target or the date, and never diagnoses anything.
 */
class SafePaceValidator
{
    /** 2 lb expressed in grams, the upper end of the CDC's stated range. */
    public const LOSS_LIMIT_GRAMS_PER_WEEK = '907.1847';

    /** This application's own conservative limit, not published guidance. */
    public const GAIN_LIMIT_GRAMS_PER_WEEK = '500';

    /** Values are stored as `DECIMAL(12,4)`; comparisons match that precision. */
    public const COMPARISON_PRECISION = 4;

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    public function warningsFor(
        BodyMetric $metric,
        string $direction,
        string $startingValue,
        string $targetValue,
        ?string $targetDate,
        string $today,
    ): array {
        if (! $metric->hasPaceBoundary() || $targetDate === null || $direction === 'maintain') {
            return [];
        }

        $weeks = $this->weeksBetween($today, $targetDate);

        if ($weeks === null) {
            return [];
        }

        $change = abs((float) $targetValue - (float) $startingValue);

        if ($change === 0.0) {
            return [];
        }

        $ratePerWeek = $change / $weeks;
        $limit = $direction === 'gain'
            ? (float) self::GAIN_LIMIT_GRAMS_PER_WEEK
            : (float) self::LOSS_LIMIT_GRAMS_PER_WEEK;

        // Exactly at the boundary is acceptable; only faster is worth saying.
        //
        // Both sides are rounded to the precision the value is stored at first.
        // Without that, a target derived from the limit itself comes back from
        // the database a few float bits off and warns about a rate the user was
        // told is fine.
        if (round($ratePerWeek, self::COMPARISON_PRECISION) <= round($limit, self::COMPARISON_PRECISION)) {
            return [];
        }

        return [[
            'field' => 'target_date',
            'code' => 'pace_above_guidance',
            'message' => $this->message($direction, $ratePerWeek),
        ]];
    }

    private function message(string $direction, float $ratePerWeek): string
    {
        $rate = number_format($ratePerWeek / 1000, 2, '.', '');

        if ($direction === 'gain') {
            return "That target needs about {$rate} kg a week. SelfHandler treats more than 0.5 kg a week "
                .'as fast; that is this application\'s own limit rather than published guidance. '
                .'The goal was saved exactly as you entered it.';
        }

        return "That target needs about {$rate} kg a week. The CDC describes 1 to 2 pounds a week as a "
            .'gradual, steady pace. The goal was saved exactly as you entered it.';
    }

    private function weeksBetween(string $today, string $targetDate): ?float
    {
        $start = CarbonImmutable::parse($today, 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($targetDate, 'UTC')->startOfDay();
        $days = $start->diffInDays($end, false);

        return $days > 0 ? $days / 7 : null;
    }
}
