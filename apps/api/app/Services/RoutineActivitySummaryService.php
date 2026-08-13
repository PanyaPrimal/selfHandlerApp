<?php

namespace App\Services;

use App\Models\Routine;
use Illuminate\Support\Collection;

class RoutineActivitySummaryService
{
    /** @param Collection<int, Routine> $routines
     * @return array<string, mixed>
     */
    public function summarize(Collection $routines): array
    {
        $templates = $routines->filter(fn (Routine $routine): bool => $routine->activities->isNotEmpty())
            ->map(function (Routine $routine): array {
                $scheduled = $routine->activities->count();
                $done = $routine->activities->filter(fn ($activity): bool => $activity->logs->first()?->status === 'done')->count();
                $skipped = $routine->activities->filter(fn ($activity): bool => $activity->logs->first()?->status === 'skipped')->count();

                return [
                    'routine_id' => $routine->id,
                    'name' => $routine->name,
                    'scheduled' => $scheduled,
                    'done' => $done,
                    'skipped' => $skipped,
                    'pending' => $scheduled - $done - $skipped,
                    'completion_rate' => $scheduled === 0 ? null : round($done * 100 / $scheduled, 1),
                ];
            })->values();
        $scheduled = $templates->sum('scheduled');
        $done = $templates->sum('done');
        $skipped = $templates->sum('skipped');

        return [
            'scheduled' => $scheduled,
            'done' => $done,
            'skipped' => $skipped,
            'pending' => $scheduled - $done - $skipped,
            'completion_rate' => $scheduled === 0 ? null : round($done * 100 / $scheduled, 1),
            'templates' => $templates->all(),
        ];
    }
}
