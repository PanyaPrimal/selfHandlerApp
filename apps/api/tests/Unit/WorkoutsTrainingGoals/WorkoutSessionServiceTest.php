<?php

namespace Tests\Unit\WorkoutsTrainingGoals;

use App\Models\PlannedOccurrence;
use App\Models\WorkoutProgram;
use App\Models\WorkoutSession;
use App\Services\WorkoutSessionService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\WorkoutsTrainingGoals\WorkoutTestCase;

class WorkoutSessionServiceTest extends WorkoutTestCase
{
    public function test_planned_detailed_session_is_idempotent_and_correction_replaces_children_atomically(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $this->addPrescription($program);
        $service = app(WorkoutSessionService::class);

        $first = $service->upsertPlanned($program, $owner, self::TODAY, $this->strengthPayload());
        $payload = $this->strengthPayload();
        $payload['note'] = 'Corrected';
        $payload['strength']['exercises'][0]['sets'] = [[
            'set_order' => 0, 'weight_kg' => 52.5, 'reps' => 5, 'rest_seconds' => 120,
        ]];
        $corrected = $service->upsertPlanned($program, $owner, self::TODAY, $payload);

        $this->assertSame($first->id, $corrected->id);
        $this->assertDatabaseCount('workout_sessions', 1);
        $this->assertDatabaseCount('workout_strength_details', 1);
        $this->assertDatabaseCount('workout_session_exercises', 1);
        $this->assertDatabaseCount('workout_sets', 1);
        $this->assertDatabaseHas('workout_sets', ['weight_kg' => 52.5, 'reps' => 5]);
        $this->assertSame($first->id, $this->occurrenceOn($program)->workout_session_id);
        $this->assertSame(PlannedOccurrence::STATUS_DONE, $this->occurrenceOn($program)->status);
    }

    public function test_skip_has_no_subtype_and_complete_then_delete_restores_pending(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $this->addPrescription($program);
        $service = app(WorkoutSessionService::class);

        $skipped = $service->upsertPlanned($program, $owner, self::TODAY, [
            'outcome' => WorkoutSession::OUTCOME_SKIPPED,
            'note' => 'Travel',
        ]);

        $this->assertNull($skipped->strengthDetail);
        $this->assertNull($skipped->duration_seconds);
        $this->assertSame(PlannedOccurrence::STATUS_SKIPPED, $this->occurrenceOn($program)->status);

        $completed = $service->upsertPlanned($program, $owner, self::TODAY, $this->strengthPayload());
        $this->assertSame($skipped->id, $completed->id);
        $this->assertNotNull($completed->strengthDetail);
        $this->assertSame(PlannedOccurrence::STATUS_DONE, $this->occurrenceOn($program)->status);

        $service->delete($completed, $owner);

        $this->assertDatabaseMissing('workout_sessions', ['id' => $completed->id]);
        $this->assertNull($this->occurrenceOn($program)->workout_session_id);
        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $this->occurrenceOn($program)->status);
    }

    public function test_manual_simple_session_can_coexist_with_planned_session_on_same_date(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $this->addPrescription($program);
        $planned = $this->createPlannedSession($program, $owner);

        $manual = app(WorkoutSessionService::class)->createManual($owner, [
            'name' => 'Extra pull-ups',
            'workout_type' => WorkoutProgram::TYPE_STRENGTH,
            'performed_on' => self::TODAY,
            'started_time' => null,
            'duration_seconds' => null,
            'note' => null,
            'strength' => [
                'mode' => 'simple',
                'exercises' => [[
                    'exercise_id' => $this->builtInExercise('pull_up')->id,
                    'sort_order' => 0,
                    'simple_weight_kg' => 0,
                    'simple_reps' => 10,
                    'note' => null,
                    'sets' => [],
                ]],
            ],
        ]);

        $this->assertNotSame($planned->id, $manual->id);
        $this->assertNull($manual->workout_program_id);
        $this->assertNull($manual->plannedOccurrence);
        $this->assertSame('0.000', $manual->strengthDetail->exercises->first()->simple_weight_kg);
        $this->assertDatabaseCount('workout_sessions', 2);
    }

    public function test_endurance_and_timed_types_store_only_matching_details_and_derive_utc_start(): void
    {
        $owner = $this->createUser(timezone: 'Europe/Kyiv');
        $service = app(WorkoutSessionService::class);
        $run = $service->createManual($owner, [
            'name' => 'Easy 5K', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
            'started_time' => '07:30', 'duration_seconds' => 1800, 'note' => null,
            'endurance' => [
                'activity' => 'running', 'run_type' => 'easy', 'distance_m' => 5000,
                'average_heart_rate' => 145, 'energy_kcal' => 350,
            ],
        ]);
        $yoga = $service->createManual($owner, [
            'name' => 'Yoga', 'workout_type' => 'flexibility', 'performed_on' => self::TODAY,
            'started_time' => null, 'duration_seconds' => 1200, 'note' => null,
            'timed' => ['activity_name' => 'Mobility flow'],
        ]);

        $this->assertSame('2026-08-13 04:30:00', $run->started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(5000, $run->enduranceDetail->distance_m);
        $this->assertNull($run->strengthDetail);
        $this->assertSame('Mobility flow', $yoga->timedDetail->activity_name);
        $this->assertNull($yoga->enduranceDetail);
    }

    public function test_invalid_dst_subtype_values_and_unscheduled_dates_leave_no_partial_fact(): void
    {
        $owner = $this->createUser(timezone: 'Europe/Kyiv', locale: 'uk-UA');
        $service = app(WorkoutSessionService::class);

        foreach ([
            [
                'name' => 'Gap run', 'workout_type' => 'cardio', 'performed_on' => '2026-03-29',
                'started_time' => '03:30', 'duration_seconds' => 1800,
                'endurance' => ['activity' => 'running', 'run_type' => 'easy', 'distance_m' => 5000],
            ],
            [
                'name' => 'Mixed', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
                'duration_seconds' => 100, 'strength' => ['mode' => 'simple', 'exercises' => []],
                'endurance' => ['activity' => 'running', 'distance_m' => -1],
            ],
        ] as $payload) {
            try {
                $service->createManual($owner, $payload);
                $this->fail('Expected invalid session rejection.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $program = $this->createProgram($owner, [], ['schedule_type' => 'weekdays'], ['MO']);
        $this->addPrescription($program);
        try {
            $service->upsertPlanned($program, $owner, self::TODAY, $this->strengthPayload());
            $this->fail('Thursday is not scheduled for this program.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('date', $exception->errors());
        }

        $this->assertDatabaseCount('workout_sessions', 0);
        $this->assertDatabaseCount('workout_sets', 0);
    }

    public function test_foreign_program_session_and_exercise_are_rejected(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $foreignProgram = $this->createProgram($other);
        $foreignExercise = $this->createCustomExercise($other);

        try {
            app(WorkoutSessionService::class)->upsertPlanned(
                $foreignProgram,
                $owner,
                self::TODAY,
                $this->strengthPayload(),
            );
            $this->fail('Expected foreign program rejection.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }

        $payload = [
            'name' => 'Foreign exercise', 'workout_type' => 'strength', 'performed_on' => self::TODAY,
            'strength' => ['mode' => 'simple', 'exercises' => [[
                'exercise_id' => $foreignExercise->id, 'sort_order' => 0,
                'simple_weight_kg' => 10, 'simple_reps' => 5, 'sets' => [],
            ]]],
        ];
        $this->expectException(ValidationException::class);
        app(WorkoutSessionService::class)->createManual($owner, $payload);
    }
}
