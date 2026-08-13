<?php

namespace Tests\Unit\WorkoutsTrainingGoals;

use App\Services\WorkoutProgressionService;
use App\Services\WorkoutSessionService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\WorkoutsTrainingGoals\WorkoutTestCase;

class WorkoutProgressionServiceTest extends WorkoutTestCase
{
    public function test_two_successes_increment_failure_resets_and_correction_recalculates(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $prescription = $this->addPrescription($program);
        $sessions = [];
        foreach ([self::TODAY, self::TOMORROW] as $date) {
            $sessions[] = $this->createPlannedSession($program, $owner, $date, $this->strengthPayload());
        }

        $afterTwo = app(WorkoutProgressionService::class)->forProgram($program, self::TOMORROW);
        $this->assertSame('52.500', $afterTwo[$prescription->id]['next_weight_kg']);
        $this->assertSame(0, $afterTwo[$prescription->id]['successful_sessions']);
        $this->assertSame(2, $afterTwo[$prescription->id]['successes_remaining']);

        $failureDate = '2026-08-15';
        $failure = $this->strengthPayload();
        foreach ($failure['strength']['exercises'][0]['sets'] as &$set) {
            $set['weight_kg'] = 50;
        }
        unset($set);
        $sessions[] = $this->createPlannedSession($program, $owner, $failureDate, $failure);
        $afterFailure = app(WorkoutProgressionService::class)->forProgram($program, $failureDate);
        $this->assertSame('52.500', $afterFailure[$prescription->id]['next_weight_kg']);
        $this->assertSame(0, $afterFailure[$prescription->id]['successful_sessions']);

        $corrected = $this->strengthPayload();
        foreach ($corrected['strength']['exercises'][0]['sets'] as &$set) {
            $set['weight_kg'] = 52.5;
        }
        unset($set);
        app(WorkoutSessionService::class)->update($sessions[2], $owner, $corrected);
        $afterCorrection = app(WorkoutProgressionService::class)->forProgram($program, $failureDate);

        $this->assertSame('52.500', $afterCorrection[$prescription->id]['next_weight_kg']);
        $this->assertSame(1, $afterCorrection[$prescription->id]['successful_sessions']);
        $this->assertSame(1, $afterCorrection[$prescription->id]['successes_remaining']);
    }

    public function test_skip_and_unrelated_exercise_do_not_qualify_or_reset_progression(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $prescription = $this->addPrescription($program);
        $this->createPlannedSession($program, $owner);
        $this->createPlannedSession($program, $owner, self::TOMORROW, [
            'outcome' => 'skipped', 'note' => null,
        ]);

        $progress = app(WorkoutProgressionService::class)->forProgram($program, self::TOMORROW);

        $this->assertSame('50.000', $progress[$prescription->id]['next_weight_kg']);
        $this->assertSame(1, $progress[$prescription->id]['successful_sessions']);
        $this->assertSame(1, $progress[$prescription->id]['successes_remaining']);
    }

    public function test_no_history_returns_starting_target_and_full_remaining_count(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $prescription = $this->addPrescription($program, overrides: [
            'starting_weight_kg' => 37.5, 'successes_required' => 3,
        ]);

        $progress = app(WorkoutProgressionService::class)->forProgram($program, self::TODAY);

        $this->assertSame('37.500', $progress[$prescription->id]['next_weight_kg']);
        $this->assertSame(0, $progress[$prescription->id]['successful_sessions']);
        $this->assertSame(3, $progress[$prescription->id]['successes_remaining']);
    }

    public function test_multiple_programs_are_loaded_with_a_fixed_query_budget(): void
    {
        $owner = $this->createUser();
        $programs = collect();
        foreach (range(1, 8) as $index) {
            $program = $this->createProgram($owner, [], ['name' => "Program {$index}"]);
            $this->addPrescription($program);
            $programs->push($program);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(WorkoutProgressionService::class)->forPrograms($programs, self::TODAY);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queries);
    }
}
