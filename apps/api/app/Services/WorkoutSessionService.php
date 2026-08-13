<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use App\Models\WorkoutEnduranceDetail;
use App\Models\WorkoutProgram;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionExercise;
use App\Models\WorkoutSet;
use App\Models\WorkoutStrengthDetail;
use App\Models\WorkoutTimedDetail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WorkoutSessionService
{
    public function __construct(
        private readonly ExerciseCatalogueService $catalogue,
        private readonly OccurrenceFactSynchronizer $occurrences,
        private readonly RecurringRuleExpander $expander,
    ) {}

    /** @param array<string, mixed> $data */
    public function upsertPlanned(WorkoutProgram $program, User $user, string $date, array $data): WorkoutSession
    {
        $this->assertProgramOwner($program, $user);

        return DB::transaction(function () use ($program, $user, $date, $data): WorkoutSession {
            $occurrence = $this->occurrence($program, $date, true);
            $session = WorkoutSession::query()->firstOrNew([
                'user_id' => $user->id,
                'workout_program_id' => $program->id,
                'performed_on' => $date,
            ]);
            $session->fill([
                'name' => $program->name,
                'workout_type' => $program->workout_type,
                'outcome' => $data['outcome'] ?? null,
                'started_at' => $this->startedAt($date, $data['started_time'] ?? null, $user),
                'duration_seconds' => $data['duration_seconds'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
            $this->validateSession($session, $data, $user, $program);
            $session->save();
            $this->replaceSubtype($session, $user, $data);
            $this->occurrences->syncFromWorkoutSession($session);
            $occurrence->refresh();

            return $this->load($session->fresh());
        });
    }

    /** @param array<string, mixed> $data */
    public function createManual(User $user, array $data): WorkoutSession
    {
        return DB::transaction(function () use ($user, $data): WorkoutSession {
            $date = (string) ($data['performed_on'] ?? '');
            $session = new WorkoutSession([
                'user_id' => $user->id,
                'workout_program_id' => null,
                'name' => $data['name'] ?? null,
                'workout_type' => $data['workout_type'] ?? null,
                'outcome' => WorkoutSession::OUTCOME_COMPLETED,
                'performed_on' => $date,
                'started_at' => $this->startedAt($date, $data['started_time'] ?? null, $user),
                'duration_seconds' => $data['duration_seconds'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
            $this->validateSession($session, $data, $user, null);
            $session->save();
            $this->replaceSubtype($session, $user, $data);

            return $this->load($session->fresh());
        });
    }

    /** @param array<string, mixed> $data */
    public function update(WorkoutSession $session, User $user, array $data): WorkoutSession
    {
        $this->assertSessionOwner($session, $user);
        $merged = [...$this->snapshot($session), ...$data];
        if ($session->workout_program_id !== null) {
            return $this->upsertPlanned($session->program, $user, $session->performed_on->format('Y-m-d'), $merged);
        }

        return DB::transaction(function () use ($session, $user, $merged): WorkoutSession {
            $date = (string) $merged['performed_on'];
            $session->fill([
                'name' => $merged['name'],
                'performed_on' => $date,
                'started_at' => $this->startedAt($date, $merged['started_time'] ?? null, $user),
                'duration_seconds' => $merged['duration_seconds'] ?? null,
                'note' => $merged['note'] ?? null,
            ]);
            $this->validateSession($session, $merged, $user, null);
            $session->save();
            $this->replaceSubtype($session, $user, $merged);

            return $this->load($session->fresh());
        });
    }

    public function delete(WorkoutSession $session, User $user): void
    {
        $this->assertSessionOwner($session, $user);
        DB::transaction(function () use ($session): void {
            if ($session->workout_program_id !== null) {
                $this->occurrences->clearForWorkoutDate(
                    $session->program,
                    $session->performed_on->format('Y-m-d'),
                );
            }
            $session->delete();
        });
    }

    /** @param array<string, mixed> $data */
    private function validateSession(
        WorkoutSession $session,
        array $data,
        User $user,
        ?WorkoutProgram $program,
    ): void {
        $type = (string) $session->workout_type;
        $outcome = (string) $session->outcome;
        if (! in_array($type, WorkoutProgram::TYPES, true)) {
            $this->invalid('workout_type');
        }
        if (! in_array($outcome, [WorkoutSession::OUTCOME_COMPLETED, WorkoutSession::OUTCOME_SKIPPED], true)) {
            $this->invalid('outcome');
        }
        if ($program === null && $outcome !== WorkoutSession::OUTCOME_COMPLETED) {
            $this->invalid('outcome');
        }
        if ($outcome === WorkoutSession::OUTCOME_SKIPPED) {
            if (collect(['strength', 'endurance', 'timed'])->contains(fn (string $key): bool => ! empty($data[$key]))) {
                $this->invalid('outcome');
            }

            return;
        }

        $present = collect(['strength', 'endurance', 'timed'])
            ->filter(fn (string $key): bool => is_array($data[$key] ?? null))->values()->all();
        $expected = match ($type) {
            WorkoutProgram::TYPE_STRENGTH => 'strength',
            WorkoutProgram::TYPE_CARDIO => 'endurance',
            WorkoutProgram::TYPE_FLEXIBILITY, WorkoutProgram::TYPE_SPORT => 'timed',
            default => '',
        };
        if ($present !== [$expected]) {
            $this->invalid($expected ?: 'workout_type');
        }
        if (in_array($type, [WorkoutProgram::TYPE_CARDIO, WorkoutProgram::TYPE_FLEXIBILITY, WorkoutProgram::TYPE_SPORT], true)
            && (int) ($session->duration_seconds ?? 0) < 1) {
            $this->invalid('duration_seconds');
        }

        if ($type === WorkoutProgram::TYPE_STRENGTH) {
            $this->validateStrength($data['strength'], $user, $program);
        } elseif ($type === WorkoutProgram::TYPE_CARDIO) {
            $endurance = $data['endurance'];
            $distance = $endurance['distance_m'] ?? null;
            if (($distance !== null && ((int) $distance < 1 || (int) $distance > 1_000_000))
                || (($endurance['activity'] ?? null) === 'running' && (int) $distance < 1)) {
                $this->invalid('endurance.distance_m');
            }
        } elseif ($type === WorkoutProgram::TYPE_SPORT && blank($data['timed']['activity_name'] ?? null)) {
            $this->invalid('timed.activity_name');
        }
    }

    /** @param array<string, mixed> $strength */
    private function validateStrength(array $strength, User $user, ?WorkoutProgram $program): void
    {
        $mode = $strength['mode'] ?? null;
        $exercises = $strength['exercises'] ?? [];
        if (! in_array($mode, [WorkoutStrengthDetail::MODE_SIMPLE, WorkoutStrengthDetail::MODE_DETAILED], true)
            || ! is_array($exercises) || $exercises === [] || count($exercises) > 50) {
            $this->invalid('strength');
        }
        $allowed = $program?->exercises()->pluck('exercise_id')->map(fn ($id): int => (int) $id)->all();
        $seen = [];
        foreach ($exercises as $index => $item) {
            $exerciseId = (int) ($item['exercise_id'] ?? 0);
            $this->catalogue->assertAccessible($exerciseId, $user);
            if (in_array($exerciseId, $seen, true) || ($allowed !== null && ! in_array($exerciseId, $allowed, true))) {
                $this->invalid("strength.exercises.{$index}.exercise_id");
            }
            $seen[] = $exerciseId;
            $sets = $item['sets'] ?? [];
            if ($mode === WorkoutStrengthDetail::MODE_SIMPLE) {
                if (($item['simple_weight_kg'] ?? null) === null || ($item['simple_reps'] ?? null) === null || $sets !== []) {
                    $this->invalid("strength.exercises.{$index}");
                }
            } elseif (($item['simple_weight_kg'] ?? null) !== null || ($item['simple_reps'] ?? null) !== null
                || ! is_array($sets) || $sets === [] || count($sets) > 20) {
                $this->invalid("strength.exercises.{$index}");
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function replaceSubtype(WorkoutSession $session, User $user, array $data): void
    {
        WorkoutSet::query()->whereIn('workout_session_exercise_id', WorkoutSessionExercise::query()
            ->where('workout_session_id', $session->id)->select('id'))->delete();
        WorkoutSessionExercise::query()->where('workout_session_id', $session->id)->delete();
        $session->strengthDetail()->delete();
        $session->enduranceDetail()->delete();
        $session->timedDetail()->delete();

        if ($session->outcome === WorkoutSession::OUTCOME_SKIPPED) {
            $session->forceFill(['started_at' => null, 'duration_seconds' => null])->save();

            return;
        }
        if ($session->workout_type === WorkoutProgram::TYPE_STRENGTH) {
            $strength = $data['strength'];
            WorkoutStrengthDetail::create([
                'user_id' => $user->id, 'workout_session_id' => $session->id, 'mode' => $strength['mode'],
            ]);
            foreach ($strength['exercises'] as $item) {
                $row = WorkoutSessionExercise::create([
                    'user_id' => $user->id, 'workout_session_id' => $session->id,
                    'exercise_id' => $item['exercise_id'], 'sort_order' => $item['sort_order'],
                    'simple_weight_kg' => $item['simple_weight_kg'] ?? null,
                    'simple_reps' => $item['simple_reps'] ?? null, 'note' => $item['note'] ?? null,
                ]);
                foreach ($item['sets'] ?? [] as $set) {
                    WorkoutSet::create([
                        'user_id' => $user->id, 'workout_session_exercise_id' => $row->id,
                        'set_order' => $set['set_order'], 'weight_kg' => $set['weight_kg'],
                        'reps' => $set['reps'], 'rest_seconds' => $set['rest_seconds'] ?? null,
                    ]);
                }
            }
        } elseif ($session->workout_type === WorkoutProgram::TYPE_CARDIO) {
            WorkoutEnduranceDetail::create([
                'user_id' => $user->id, 'workout_session_id' => $session->id, ...$data['endurance'],
            ]);
        } else {
            WorkoutTimedDetail::create([
                'user_id' => $user->id, 'workout_session_id' => $session->id, ...$data['timed'],
            ]);
        }
    }

    private function occurrence(WorkoutProgram $program, string $date, bool $lock): PlannedOccurrence
    {
        $query = $this->occurrenceQuery($program, $date);
        if ($lock) {
            $query->lockForUpdate();
        }
        $matches = $query->get();
        if ($matches->isEmpty()) {
            $rule = $program->recurringRule()->with('ruleWeekdays')->first();
            if ($rule && $program->is_active && ! $program->is_archived && $this->expander->occursOn($rule, $date)) {
                PlannedOccurrence::query()->firstOrCreate([
                    'recurring_rule_id' => $rule->id,
                    'occurrence_date' => $date,
                    'slot' => '',
                ], [
                    'user_id' => $program->user_id,
                    'occurrence_time' => $rule->slot_time,
                    'status' => PlannedOccurrence::STATUS_PLANNED,
                    'materialized_at' => now(),
                ]);
                $matches = $this->occurrenceQuery($program, $date)->get();
            }
        }
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['date' => __('messages.workout_date_not_scheduled')]);
        }

        return $matches->first();
    }

    private function occurrenceQuery(WorkoutProgram $program, string $date): Builder
    {
        return PlannedOccurrence::query()
            ->where(function ($query) use ($date): void {
                $query->where(fn ($original) => $original
                    ->where('occurrence_date', $date)->whereNull('rescheduled_to'))
                    ->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->where('owner_type', RecurringRule::OWNER_WORKOUT_PROGRAM)
                ->where('owner_id', $program->id)->select('id'));
    }

    private function startedAt(string $date, mixed $time, User $user): ?CarbonImmutable
    {
        if ($time === null || $time === '') {
            return null;
        }
        $wall = "{$date} {$time}";
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i', $wall, $user->calendarTimezone());
        if (! $parsed || $parsed->format('Y-m-d H:i') !== $wall) {
            throw ValidationException::withMessages(['started_time' => __('messages.workout_time_nonexistent')]);
        }

        return $parsed->utc();
    }

    /** @return array<string, mixed> */
    private function snapshot(WorkoutSession $session): array
    {
        $this->load($session);
        $data = [
            'name' => $session->name,
            'workout_type' => $session->workout_type,
            'outcome' => $session->outcome,
            'performed_on' => $session->performed_on->format('Y-m-d'),
            'started_time' => $session->started_at?->setTimezone($session->user->calendarTimezone())->format('H:i'),
            'duration_seconds' => $session->duration_seconds,
            'note' => $session->note,
        ];
        if ($session->strengthDetail) {
            $data['strength'] = [
                'mode' => $session->strengthDetail->mode,
                'exercises' => $session->strengthDetail->exercises->map(fn ($row): array => [
                    'exercise_id' => $row->exercise_id, 'sort_order' => $row->sort_order,
                    'simple_weight_kg' => $row->simple_weight_kg, 'simple_reps' => $row->simple_reps,
                    'note' => $row->note,
                    'sets' => $row->sets->map(fn ($set): array => [
                        'set_order' => $set->set_order, 'weight_kg' => $set->weight_kg,
                        'reps' => $set->reps, 'rest_seconds' => $set->rest_seconds,
                    ])->all(),
                ])->all(),
            ];
        } elseif ($session->enduranceDetail) {
            $data['endurance'] = $session->enduranceDetail->only([
                'activity', 'run_type', 'distance_m', 'average_heart_rate', 'energy_kcal',
            ]);
        } elseif ($session->timedDetail) {
            $data['timed'] = $session->timedDetail->only(['activity_name']);
        }

        return $data;
    }

    private function load(WorkoutSession $session): WorkoutSession
    {
        return $session->load([
            'user.profile', 'program', 'plannedOccurrence', 'strengthDetail.exercises.exercise',
            'strengthDetail.exercises.sets', 'enduranceDetail', 'timedDetail',
        ]);
    }

    private function assertProgramOwner(WorkoutProgram $program, User $user): void
    {
        if (! $program->isOwnedBy($user)) {
            throw new NotFoundHttpException;
        }
    }

    private function assertSessionOwner(WorkoutSession $session, User $user): void
    {
        if (! $session->isOwnedBy($user)) {
            throw new NotFoundHttpException;
        }
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => __('messages.workout_invalid')]);
    }
}
