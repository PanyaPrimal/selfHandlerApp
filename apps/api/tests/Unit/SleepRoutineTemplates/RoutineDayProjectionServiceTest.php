<?php

namespace Tests\Unit\SleepRoutineTemplates;

use App\Models\Routine;
use App\Models\RoutineDaySelection;
use App\Models\RoutineLog;
use App\Services\RoutineDayProjectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\SleepRoutineTemplates\SleepRoutineTestCase;

class RoutineDayProjectionServiceTest extends SleepRoutineTestCase
{
    public function test_defaults_are_deterministic_and_anytime_routines_are_never_filtered(): void
    {
        $owner = $this->createUser();
        $later = $this->createRoutine($owner, ['name' => 'Beta', 'sort_order' => 1]);
        $alphaSecond = $this->createRoutine($owner, ['name' => 'Alpha', 'sort_order' => 0]);
        $alphaFirst = $this->createRoutine($owner, ['name' => 'Alpha', 'sort_order' => 0]);
        $evening = $this->createRoutine($owner, [
            'name' => 'Evening', 'day_period' => Routine::DAY_PERIOD_EVENING,
        ]);
        $anytime = $this->createRoutine($owner, [
            'name' => 'Anytime', 'day_period' => Routine::DAY_PERIOD_ANYTIME,
        ]);

        $projection = app(RoutineDayProjectionService::class)->project($owner, self::TODAY);

        $this->assertSame($alphaSecond->id, $projection['morning']['selected']['routine_id']);
        $this->assertSame([$alphaSecond->id, $alphaFirst->id, $later->id], array_column(
            $projection['morning']['candidates'],
            'routine_id',
        ));
        $this->assertSame($evening->id, $projection['evening']['selected']['routine_id']);
        $this->assertSame([$anytime->id], array_column($projection['anytime'], 'routine_id'));
        $this->assertSame('default', $projection['morning']['source']);
    }

    public function test_explicit_choices_and_explicit_none_replace_both_periods_atomically(): void
    {
        $owner = $this->createUser();
        $morning = $this->createRoutine($owner);
        $alternate = $this->createRoutine($owner, ['name' => 'Alternate', 'sort_order' => 1]);
        $evening = $this->createRoutine($owner, ['day_period' => Routine::DAY_PERIOD_EVENING]);
        $service = app(RoutineDayProjectionService::class);

        $explicit = $service->replace($owner, self::TODAY, [
            'morning_routine_id' => $alternate->id,
            'evening_routine_id' => $evening->id,
        ]);
        $this->assertSame($alternate->id, $explicit['morning']['selected']['routine_id']);
        $this->assertSame($evening->id, $explicit['evening']['selected']['routine_id']);
        $this->assertSame('explicit', $explicit['morning']['source']);

        $none = $service->replace($owner, self::TODAY, [
            'morning_routine_id' => null,
            'evening_routine_id' => $evening->id,
        ]);
        $this->assertNull($none['morning']['selected']);
        $this->assertSame('explicit', $none['morning']['source']);
        $this->assertSame(2, RoutineDaySelection::query()->ownedBy($owner)->count());

        RoutineDaySelection::query()->ownedBy($owner)
            ->where('period', Routine::DAY_PERIOD_MORNING)->delete();
        $default = $service->project($owner, self::TODAY);
        $this->assertSame($morning->id, $default['morning']['selected']['routine_id']);
        $this->assertSame('default', $default['morning']['source']);
    }

    public function test_foreign_wrong_period_unscheduled_and_moved_away_candidates_fail_without_mutation(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $default = $this->createRoutine($owner);
        $evening = $this->createRoutine($owner, ['day_period' => Routine::DAY_PERIOD_EVENING]);
        $unscheduled = $this->createRoutine($owner, ['name' => 'Friday only'], weekdays: ['FR']);
        $moved = $this->createRoutine($owner, ['name' => 'Moved']);
        $this->routineOccurrenceOn($moved)->update(['rescheduled_to' => '2026-08-20']);
        $foreign = $this->createRoutine($other);
        $service = app(RoutineDayProjectionService::class);

        foreach ([$evening, $unscheduled, $moved, $foreign] as $invalid) {
            try {
                $service->replace($owner, self::TODAY, [
                    'morning_routine_id' => $invalid->id,
                    'evening_routine_id' => null,
                ]);
                $this->fail('Invalid candidate must fail.');
            } catch (ValidationException|NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame(0, RoutineDaySelection::query()->ownedBy($owner)->count());
        }

        $this->assertSame($default->id, $service->project($owner, self::TODAY)['morning']['selected']['routine_id']);
    }

    public function test_selection_cannot_hide_a_fact_bearing_template(): void
    {
        $owner = $this->createUser();
        $selected = $this->createRoutine($owner);
        $alternate = $this->createRoutine($owner, ['name' => 'Alternate', 'sort_order' => 1]);
        RoutineLog::create([
            'user_id' => $owner->id,
            'routine_id' => $selected->id,
            'log_date' => self::TODAY,
            'status' => RoutineLog::STATUS_DONE,
            'completed_at' => now(),
        ]);

        try {
            app(RoutineDayProjectionService::class)->replace($owner, self::TODAY, [
                'morning_routine_id' => $alternate->id,
                'evening_routine_id' => null,
            ]);
            $this->fail('A fact-bearing selected routine cannot be hidden.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('morning_routine_id', $error->errors());
        }
        $this->assertSame($selected->id, app(RoutineDayProjectionService::class)
            ->project($owner, self::TODAY)['morning']['selected']['routine_id']);
    }

    public function test_repeated_replacement_is_unique_and_projection_queries_are_bounded(): void
    {
        $owner = $this->createUser();
        $morning = $this->createRoutine($owner);
        $evening = $this->createRoutine($owner, ['day_period' => Routine::DAY_PERIOD_EVENING]);
        $service = app(RoutineDayProjectionService::class);

        for ($index = 0; $index < 3; $index++) {
            $service->replace($owner, self::TODAY, [
                'morning_routine_id' => $morning->id,
                'evening_routine_id' => $evening->id,
            ]);
        }
        $this->assertSame(2, RoutineDaySelection::query()->ownedBy($owner)->count());

        for ($index = 0; $index < 12; $index++) {
            $this->createRoutine($owner, ['name' => "Candidate {$index}", 'sort_order' => $index + 1]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->project($owner, self::TODAY);
        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()));
    }
}
