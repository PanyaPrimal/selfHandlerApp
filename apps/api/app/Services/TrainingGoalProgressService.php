<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\TrainingGoalDetail;
use App\Models\User;
use App\Models\WorkoutSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class TrainingGoalProgressService
{
    /** @return array<string, mixed> */
    public function describe(Goal $goal): array
    {
        return $this->describeMany(collect([$goal]))[$goal->id];
    }

    /** @param Collection<int, Goal> $goals @return array<int, array<string, mixed>> */
    public function describeMany(Collection $goals): array
    {
        if ($goals->isEmpty()) {
            return [];
        }
        $goals = new EloquentCollection($goals->all());
        $goals->loadMissing(['trainingDetail.exercise', 'trainingDetail.program', 'user.profile']);
        $user = $goals->first()->user;
        $sessions = WorkoutSession::query()->ownedBy($user)
            ->where('outcome', WorkoutSession::OUTCOME_COMPLETED)
            ->with(['strengthDetail.exercises.exercise', 'strengthDetail.exercises.sets', 'enduranceDetail'])
            ->orderBy('performed_on')->orderBy('id')->get();

        $result = [];
        foreach ($goals as $goal) {
            $detail = $goal->trainingDetail;
            [$current, $currentOn] = $this->current($detail, $sessions, $user);
            $start = (float) $detail->starting_value;
            $target = (float) $detail->target_value;
            $progress = $current === null ? null : ($target === $start
                ? ($current >= $target ? 1.0 : 0.0)
                : max(0.0, min(1.0, ($current - $start) / ($target - $start))));
            $result[$goal->id] = [
                'kind' => $detail->kind,
                'unit' => $detail->kind === TrainingGoalDetail::KIND_STRENGTH
                    ? 'kg' : ($detail->kind === TrainingGoalDetail::KIND_CONSISTENCY ? 'sessions_per_week' : 'm'),
                'exercise' => $detail->exercise,
                'activity' => $detail->activity,
                'workout_program_id' => $detail->workout_program_id,
                'starting_value' => $detail->starting_value,
                'target_value' => $detail->target_value,
                'current_value' => $current === null ? null : number_format($current, 3, '.', ''),
                'current_on' => $currentOn,
                'progress' => $progress,
            ];
        }

        return $result;
    }

    /** @return array{0: float|null, 1: string|null} */
    public function currentFor(
        User $user,
        string $kind,
        ?int $exerciseId,
        ?string $activity,
        ?int $programId,
    ): array {
        $detail = new TrainingGoalDetail([
            'user_id' => $user->id, 'kind' => $kind, 'exercise_id' => $exerciseId,
            'activity' => $activity, 'workout_program_id' => $programId,
        ]);
        $sessions = WorkoutSession::query()->ownedBy($user)
            ->where('outcome', WorkoutSession::OUTCOME_COMPLETED)
            ->with(['strengthDetail.exercises.sets', 'enduranceDetail'])
            ->orderBy('performed_on')->orderBy('id')->get();

        return $this->current($detail, $sessions, $user);
    }

    /** @param Collection<int, WorkoutSession> $sessions @return array{0: float|null, 1: string|null} */
    private function current(TrainingGoalDetail $detail, Collection $sessions, User $user): array
    {
        $scoped = $detail->workout_program_id === null
            ? $sessions
            : $sessions->where('workout_program_id', $detail->workout_program_id);
        if ($detail->kind === TrainingGoalDetail::KIND_CONSISTENCY) {
            $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();
            $from = CarbonImmutable::parse($today, $user->calendarTimezone())->subDays(6)->toDateString();
            $matching = $scoped->filter(fn (WorkoutSession $session): bool => $session->performed_on->format('Y-m-d') >= $from
                && $session->performed_on->format('Y-m-d') <= $today);
            if ($matching->isEmpty()) {
                return [null, null];
            }

            return [(float) $matching->unique('id')->count(), $matching->max('performed_on')->format('Y-m-d')];
        }
        if ($detail->kind === TrainingGoalDetail::KIND_STRENGTH) {
            $best = null;
            $date = null;
            foreach ($scoped as $session) {
                $exercise = $session->strengthDetail?->exercises->firstWhere('exercise_id', $detail->exercise_id);
                if (! $exercise) {
                    continue;
                }
                $weights = $exercise->sets->pluck('weight_kg')->map(fn ($value): float => (float) $value);
                if ($exercise->simple_weight_kg !== null) {
                    $weights->push((float) $exercise->simple_weight_kg);
                }
                $candidate = $weights->max();
                if ($candidate !== null && ($best === null || $candidate > $best)) {
                    $best = $candidate;
                    $date = $session->performed_on->format('Y-m-d');
                }
            }

            return [$best, $date];
        }

        $best = null;
        $date = null;
        foreach ($scoped as $session) {
            $endurance = $session->enduranceDetail;
            if (! $endurance || $endurance->activity !== $detail->activity || $endurance->distance_m === null) {
                continue;
            }
            if ($best === null || $endurance->distance_m > $best) {
                $best = (float) $endurance->distance_m;
                $date = $session->performed_on->format('Y-m-d');
            }
        }

        return [$best, $date];
    }
}
