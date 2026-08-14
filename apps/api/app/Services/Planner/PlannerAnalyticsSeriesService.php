<?php

namespace App\Services\Planner;

use App\Models\Item;
use App\Models\PlannedOccurrence;
use App\Models\User;

class PlannerAnalyticsSeriesService
{
    /** @return list<array<string,mixed>> */
    public function daily(User $user, string $from, string $to): array
    {
        $occurrences = PlannedOccurrence::query()->ownedBy($user)
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($original) use ($from, $to): void {
                    $original->whereBetween('occurrence_date', [$from, $to])->whereNull('rescheduled_to');
                })->orWhereBetween('rescheduled_to', [$from, $to]);
            })->get(['occurrence_date', 'rescheduled_to', 'status']);
        $items = Item::query()->ownedBy($user)->whereBetween('due_on', [$from, $to])->get(['due_on', 'status']);
        $days = [];
        foreach ($occurrences as $occurrence) {
            $date = ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');
            $this->count($days, $date, $occurrence->status === PlannedOccurrence::STATUS_DONE);
        }
        foreach ($items as $item) {
            $this->count($days, $item->due_on->format('Y-m-d'), $item->status === Item::STATUS_DONE);
        }
        ksort($days);

        return array_map(fn (string $date, array $day): array => [
            'date' => $date, 'numerator' => (string) $day['done'], 'denominator' => (string) $day['scheduled'],
            'sample_count' => $day['scheduled'], 'complete' => true, 'reasons' => [],
        ], array_keys($days), array_values($days));
    }

    /** @param array<string,array{done:int,scheduled:int}> $days */
    private function count(array &$days, string $date, bool $done): void
    {
        $days[$date] ??= ['done' => 0, 'scheduled' => 0];
        $days[$date]['scheduled']++;
        if ($done) {
            $days[$date]['done']++;
        }
    }
}
