<?php

namespace App\Services;

use App\Models\BodyMeasurement;
use App\Models\Goal;
use Carbon\CarbonImmutable;

/**
 * Progress and milestone achievement for a body goal.
 *
 * The Body module owns these numbers: Analytics and Review will display them,
 * not recompute them. Everything here is derived from the measurement history,
 * so nothing can drift away from the observations.
 */
class BodyGoalProgressService
{
    /**
     * @return array<string, mixed>|null
     */
    public function describe(Goal $goal): ?array
    {
        $detail = $goal->bodyDetail;

        if (! $detail) {
            return null;
        }

        $today = CarbonImmutable::now($goal->user->calendarTimezone())->toDateString();

        $current = BodyMeasurement::query()
            ->where('user_id', $goal->user_id)
            ->where('metric', $detail->metric->value)
            ->where('measured_on', '<=', $today)
            ->orderByDesc('measured_on')
            ->first(['measured_on', 'value']);

        $currentValue = $current ? (string) $current->value : null;

        return [
            'metric' => $detail->metric->value,
            'metric_label' => $detail->metric->label(),
            'direction' => $detail->direction,
            'starting_value' => (string) $detail->starting_value,
            'target_value' => (string) $detail->target_value,
            'current_value' => $currentValue,
            'measured_on' => $current?->measured_on->format('Y-m-d'),
            // Absent history is "no current value", not zero progress.
            'progress' => $currentValue === null
                ? null
                : $this->progress(
                    (float) $detail->starting_value,
                    (float) $detail->target_value,
                    (float) $currentValue,
                ),
            'milestones' => $goal->milestones
                ->sortBy(fn ($milestone): float => $this->distanceFromStart($detail, $milestone))
                ->values()
                ->map(fn ($milestone): array => [
                    'id' => $milestone->id,
                    'target_value' => (string) $milestone->target_value,
                    'target_date' => $milestone->target_date?->format('Y-m-d'),
                    'achieved' => $currentValue !== null && $this->reached(
                        (float) $detail->starting_value,
                        (float) $milestone->target_value,
                        (float) $currentValue,
                    ),
                ])
                ->all(),
        ];
    }

    /** Fraction of the distance from start to target, clamped to 0..1. */
    private function progress(float $start, float $target, float $current): float
    {
        $span = $target - $start;

        if ($span === 0.0) {
            return $current === $start ? 1.0 : 0.0;
        }

        return round(max(0.0, min(1.0, ($current - $start) / $span)), 4);
    }

    /** Has the history travelled at least as far as this checkpoint? */
    private function reached(float $start, float $checkpoint, float $current): bool
    {
        return $checkpoint >= $start
            ? $current >= $checkpoint
            : $current <= $checkpoint;
    }

    /** Order milestones along the direction of travel, not by raw value. */
    private function distanceFromStart(mixed $detail, mixed $milestone): float
    {
        return abs((float) $milestone->target_value - (float) $detail->starting_value);
    }
}
