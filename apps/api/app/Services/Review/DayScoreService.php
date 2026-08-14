<?php

namespace App\Services\Review;

class DayScoreService
{
    private const TOTAL_COMPONENTS = 5;

    /** @param array<string,array<string,mixed>> $modules
     * @return array<string,mixed>
     */
    public function compose(array $modules): array
    {
        $components = [
            $this->nutrition($modules['nutrition']),
            $this->workouts($modules['workouts']),
            $this->ratio('supplements', (int) $modules['supplements']['done'],
                array_sum(array_map(fn (string $key): int => (int) $modules['supplements'][$key],
                    ['done', 'skipped', 'overdue', 'pending']))),
            $this->ratio('habits', (int) $modules['habits']['successful'], (int) $modules['habits']['scheduled']),
            $this->ratio('planner', (int) $modules['planner']['done'], (int) $modules['planner']['scheduled']),
        ];
        $available = count(array_filter($components, fn (array $component): bool => $component['available']));
        $weight = $available === 0 ? 0.0 : round(1 / $available, 6);
        $values = [];
        foreach ($components as &$component) {
            $component['weight'] = $component['available'] ? $weight : 0.0;
            if ($component['available']) {
                $values[] = $component['value'];
            }
        }
        unset($component);

        return [
            'value' => $values === [] ? null : round(array_sum($values) / count($values), 2),
            'available_components' => $available,
            'total_components' => self::TOTAL_COMPONENTS,
            'coverage_percentage' => round($available / self::TOTAL_COMPONENTS * 100, 2),
            'components' => $components,
        ];
    }

    /** @param array<string,mixed> $nutrition
     * @return array<string,mixed>
     */
    private function nutrition(array $nutrition): array
    {
        $values = [];
        foreach (['calories', 'protein', 'fat', 'carbs', 'hydration', 'quality'] as $key) {
            $percent = $nutrition['progress'][$key]['percent'] ?? null;
            if ($percent === null) {
                continue;
            }
            $numeric = (float) $percent;
            $values[] = in_array($key, ['calories', 'fat', 'carbs'], true)
                ? $this->bound(100 - abs($numeric - 100))
                : $this->bound($numeric);
        }

        return $values === []
            ? $this->unavailable('nutrition', 'no_target_evidence')
            : $this->available('nutrition', round(array_sum($values) / count($values), 2));
    }

    /** @param array<string,mixed> $workouts
     * @return array<string,mixed>
     */
    private function workouts(array $workouts): array
    {
        $planned = (int) $workouts['planned'];
        if ($planned > 0) {
            return $this->available('workouts', $this->bound((int) $workouts['completed'] / $planned * 100));
        }
        if ((int) $workouts['unplanned'] > 0 || (int) $workouts['completed'] > 0) {
            return $this->available('workouts', 100.0);
        }

        return $this->unavailable('workouts', 'no_workout');
    }

    /** @return array<string,mixed> */
    private function ratio(string $key, int $successful, int $scheduled): array
    {
        if ($scheduled === 0) {
            return $this->unavailable($key, $key === 'planner' ? 'no_planner_items' : 'no_scheduled_items');
        }

        return $this->available($key, $this->bound($successful / $scheduled * 100));
    }

    /** @return array<string,mixed> */
    private function available(string $key, float $value): array
    {
        return ['key' => $key, 'available' => true, 'value' => round($value, 2), 'weight' => 0.0, 'reason' => 'available'];
    }

    /** @return array<string,mixed> */
    private function unavailable(string $key, string $reason): array
    {
        return ['key' => $key, 'available' => false, 'value' => null, 'weight' => 0.0, 'reason' => $reason];
    }

    private function bound(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
