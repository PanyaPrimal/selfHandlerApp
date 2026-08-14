<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkoutSession;

class WorkoutAnalyticsSeriesService
{
    /** @return array{completed:list<array<string,mixed>>,duration:list<array<string,mixed>>} */
    public function daily(User $user, string $from, string $to): array
    {
        $sessions = WorkoutSession::query()->ownedBy($user)
            ->where('outcome', WorkoutSession::OUTCOME_COMPLETED)
            ->whereBetween('performed_on', [$from, $to])
            ->orderBy('performed_on')->orderBy('id')->get(['performed_on', 'duration_seconds']);
        $days = [];
        foreach ($sessions as $session) {
            $date = $session->performed_on->format('Y-m-d');
            $days[$date] ??= ['completed' => 0, 'duration_seconds' => 0];
            $days[$date]['completed']++;
            $days[$date]['duration_seconds'] += (int) ($session->duration_seconds ?? 0);
        }

        $completed = $duration = [];
        foreach ($days as $date => $day) {
            $completed[] = $this->primitive($date, (string) $day['completed'], $day['completed']);
            $duration[] = $this->primitive(
                $date,
                bcdiv((string) $day['duration_seconds'], '60', 6),
                $day['completed'],
            );
        }

        return compact('completed', 'duration');
    }

    /** @return array<string,mixed> */
    private function primitive(string $date, string $numerator, int $samples): array
    {
        return [
            'date' => $date, 'numerator' => $numerator, 'denominator' => null,
            'sample_count' => $samples, 'complete' => true, 'reasons' => [],
        ];
    }
}
