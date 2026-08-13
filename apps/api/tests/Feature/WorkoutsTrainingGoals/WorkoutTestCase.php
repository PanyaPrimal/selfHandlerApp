<?php

namespace Tests\Feature\WorkoutsTrainingGoals;

use App\Models\Exercise;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use App\Models\WorkoutProgram;
use App\Models\WorkoutProgramExercise;
use App\Models\WorkoutSession;
use App\Services\WorkoutProgramRecurrence;
use App\Services\WorkoutProgramService;
use App\Services\WorkoutSessionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class WorkoutTestCase extends TestCase
{
    use RefreshDatabase;

    protected const TODAY = '2026-08-13';

    protected const TOMORROW = '2026-08-14';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::TODAY.' 09:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function createUser(
        string $email = 'owner@example.test',
        string $timezone = 'UTC',
        string $locale = 'en-GB',
    ): User {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => null]);
        $user->ensureProfile()->update(['timezone' => $timezone, 'locale' => $locale]);
        $user->unsetRelation('profile');

        return $user->fresh();
    }

    protected function builtInExercise(string $systemKey = 'squat'): Exercise
    {
        return Exercise::query()->where('system_key', $systemKey)->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    protected function createCustomExercise(User $user, array $attributes = []): Exercise
    {
        return Exercise::create([
            'user_id' => $user->id,
            'name' => 'Custom press',
            'muscle_group' => 'chest',
            'equipment' => 'barbell',
            'exercise_type' => Exercise::TYPE_STRENGTH,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $schedule
     * @param  list<string>  $weekdays
     */
    protected function createProgram(
        User $user,
        array $attributes = [],
        array $schedule = [],
        array $weekdays = [],
    ): WorkoutProgram {
        $program = WorkoutProgram::create([
            'user_id' => $user->id,
            'name' => 'Strength A',
            'workout_type' => WorkoutProgram::TYPE_STRENGTH,
            'intensity' => WorkoutProgram::INTENSITY_MODERATE,
            ...$attributes,
        ]);

        app(WorkoutProgramRecurrence::class)->apply($program, $user, [
            'schedule_type' => $weekdays === [] ? 'daily' : 'weekdays',
            'starts_on' => self::TODAY,
            ...$schedule,
        ], $weekdays);

        return $program->fresh([
            'recurringRule.ruleWeekdays', 'exercises.exercise', 'enduranceDetail', 'timedDetail',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function addPrescription(
        WorkoutProgram $program,
        ?Exercise $exercise = null,
        array $overrides = [],
    ): WorkoutProgramExercise {
        $exercise ??= $this->builtInExercise();

        app(WorkoutProgramService::class)->replaceExercises($program, $program->user, [[
            'exercise_id' => $exercise->id,
            'sort_order' => 0,
            'target_sets' => 3,
            'target_reps' => 5,
            'starting_weight_kg' => 50,
            'increment_kg' => 2.5,
            'successes_required' => 2,
            ...$overrides,
        ]]);

        return $program->fresh('exercises.exercise')->exercises->first();
    }

    protected function occurrenceOn(WorkoutProgram $program, string $date = self::TODAY): PlannedOccurrence
    {
        return PlannedOccurrence::query()
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->where('owner_type', RecurringRule::OWNER_WORKOUT_PROGRAM)
                ->where('owner_id', $program->id)
                ->select('id'))
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    protected function strengthPayload(array $overrides = []): array
    {
        return [
            'outcome' => WorkoutSession::OUTCOME_COMPLETED,
            'started_time' => '08:00',
            'duration_seconds' => 3600,
            'note' => null,
            'strength' => [
                'mode' => 'detailed',
                'exercises' => [[
                    'exercise_id' => $this->builtInExercise()->id,
                    'sort_order' => 0,
                    'simple_weight_kg' => null,
                    'simple_reps' => null,
                    'note' => null,
                    'sets' => [
                        ['set_order' => 0, 'weight_kg' => 50, 'reps' => 5, 'rest_seconds' => 90],
                        ['set_order' => 1, 'weight_kg' => 50, 'reps' => 5, 'rest_seconds' => 90],
                        ['set_order' => 2, 'weight_kg' => 50, 'reps' => 5, 'rest_seconds' => null],
                    ],
                ]],
            ],
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $data */
    protected function createPlannedSession(
        WorkoutProgram $program,
        User $user,
        string $date = self::TODAY,
        ?array $data = null,
    ): WorkoutSession {
        return app(WorkoutSessionService::class)->upsertPlanned(
            $program,
            $user,
            $date,
            $data ?? $this->strengthPayload(),
        );
    }
}
