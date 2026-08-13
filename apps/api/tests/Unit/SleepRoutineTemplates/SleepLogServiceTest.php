<?php

namespace Tests\Unit\SleepRoutineTemplates;

use App\Models\PlannedOccurrence;
use App\Services\RecurrenceMaterializer;
use App\Services\SleepLogService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\SleepRoutineTemplates\SleepRoutineTestCase;

class SleepLogServiceTest extends SleepRoutineTestCase
{
    /** @return array<string, mixed> */
    private function validLog(array $overrides = []): array
    {
        return [
            'actual_bed_date' => self::TODAY,
            'actual_bed_time' => '23:00',
            'actual_wake_date' => self::TOMORROW,
            'actual_wake_time' => '07:00',
            'quality' => 8,
            'note' => 'Restful',
            ...$overrides,
        ];
    }

    public function test_cross_midnight_local_fields_are_stored_as_exact_utc_instants_and_duration(): void
    {
        $owner = $this->createUser(timezone: 'Europe/Kyiv');
        $plan = $this->createSleepPlan($owner);

        $log = app(SleepLogService::class)->upsert($plan, $owner, self::TODAY, $this->validLog());

        $this->assertSame('2026-08-13 20:00:00', $log->actual_bed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-14 04:00:00', $log->actual_wake_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(480, $log->durationMinutes());
        $this->assertSame($log->id, $this->sleepOccurrenceOn($plan)->sleep_log_id);
        $this->assertSame(PlannedOccurrence::STATUS_DONE, $this->sleepOccurrenceOn($plan)->status);
    }

    public function test_dst_gap_is_rejected_and_fall_back_uses_the_documented_carbon_offset(): void
    {
        $owner = $this->createUser(timezone: 'Europe/Kyiv');
        $spring = $this->createSleepPlan($owner, schedule: [
            'starts_on' => '2026-03-29',
            'ends_on' => '2026-03-29',
        ]);
        app(RecurrenceMaterializer::class)->materialize($spring->recurringRule, '2026-03-29', true);

        try {
            app(SleepLogService::class)->upsert($spring, $owner, '2026-03-29', [
                'actual_bed_date' => '2026-03-29',
                'actual_bed_time' => '03:30',
                'actual_wake_date' => '2026-03-29',
                'actual_wake_time' => '08:00',
                'quality' => 7,
            ]);
            $this->fail('A nonexistent wall time must fail.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('actual_bed_time', $error->errors());
        }
        $this->assertDatabaseCount('sleep_logs', 0);

        $spring->update(['is_active' => false]);
        $fall = $this->createSleepPlan($owner, schedule: [
            'starts_on' => '2026-10-25',
            'ends_on' => '2026-10-25',
        ]);
        app(RecurrenceMaterializer::class)->materialize($fall->recurringRule, '2026-10-25', true);
        $log = app(SleepLogService::class)->upsert($fall, $owner, '2026-10-25', [
            'actual_bed_date' => '2026-10-25',
            'actual_bed_time' => '03:30',
            'actual_wake_date' => '2026-10-25',
            'actual_wake_time' => '08:00',
            'quality' => 7,
        ]);

        $this->assertSame('2026-10-25 01:30:00', $log->actual_bed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(270, $log->durationMinutes());
    }

    public function test_reversed_overlong_wrong_night_and_unscheduled_intervals_fail_atomically(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $cases = [
            ['actual_wake_date' => self::TODAY, 'actual_wake_time' => '22:59'],
            ['actual_wake_date' => '2026-08-15', 'actual_wake_time' => '23:01'],
            ['actual_bed_date' => '2026-08-15', 'actual_wake_date' => '2026-08-16'],
        ];

        foreach ($cases as $overrides) {
            try {
                app(SleepLogService::class)->upsert($plan, $owner, self::TODAY, $this->validLog($overrides));
                $this->fail('Invalid interval must fail.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            app(SleepLogService::class)->upsert($plan, $owner, '2027-01-01', $this->validLog());
            $this->fail('An unscheduled date must fail.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('date', $error->errors());
        }

        $this->assertDatabaseCount('sleep_logs', 0);
        $this->assertNull($this->sleepOccurrenceOn($plan)->sleep_log_id);
    }

    public function test_correction_is_idempotent_keeps_one_id_and_clear_reopens_the_occurrence(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $service = app(SleepLogService::class);

        $first = $service->upsert($plan, $owner, self::TODAY, $this->validLog());
        $createdAt = $first->created_at;
        $second = $service->upsert($plan, $owner, self::TODAY, $this->validLog([
            'actual_wake_time' => '06:30',
            'quality' => 6,
            'note' => null,
        ]));

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($createdAt->equalTo($second->created_at));
        $this->assertSame(450, $second->durationMinutes());
        $this->assertSame(6, $second->quality);
        $this->assertDatabaseCount('sleep_logs', 1);

        $service->clear($plan, $owner, self::TODAY);
        $service->clear($plan, $owner, self::TODAY);

        $this->assertDatabaseCount('sleep_logs', 0);
        $this->assertNull($this->sleepOccurrenceOn($plan)->sleep_log_id);
        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $this->sleepOccurrenceOn($plan)->status);
    }

    public function test_foreign_plan_is_not_writable(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $plan = $this->createSleepPlan($other);

        $this->expectException(NotFoundHttpException::class);
        app(SleepLogService::class)->upsert($plan, $owner, self::TODAY, $this->validLog());
    }
}
