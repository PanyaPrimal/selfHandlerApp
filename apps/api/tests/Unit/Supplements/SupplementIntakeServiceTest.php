<?php

namespace Tests\Unit\Supplements;

use App\Models\SupplementIntake;
use App\Services\SupplementIntakeService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Supplements\SupplementTestCase;

class SupplementIntakeServiceTest extends SupplementTestCase
{
    public function test_upsert_is_idempotent_and_stores_an_immutable_utc_snapshot(): void
    {
        $owner = $this->createUser(timezone: 'Europe/Kyiv');
        $supplement = $this->createSupplement($owner, ['name' => 'Original label']);
        $course = $this->createCourse($owner, $supplement);
        $occurrence = $this->occurrence($course);
        $payload = [
            'outcome' => SupplementIntake::OUTCOME_TAKEN,
            'dose_quantity' => null,
            'dose_display_unit' => null,
            'taken_time' => '08:30',
            'note' => 'Accepted once',
        ];

        $first = app(SupplementIntakeService::class)->upsert($occurrence, $owner, $payload);
        $second = app(SupplementIntakeService::class)->upsert($occurrence->fresh(), $owner, $payload);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(
            $first['occurrence']->supplement_intake_id,
            $second['occurrence']->supplement_intake_id,
        );
        $this->assertDatabaseCount('supplement_intakes', 1);

        $intake = SupplementIntake::sole();
        $this->assertSame('2026-08-13 05:30:00', $intake->taken_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('1.000000', $intake->dose_quantity);
        $this->assertSame('Original label', $intake->supplement_name);

        $supplement->update(['name' => 'Renamed later']);
        $course->update(['dose_quantity' => '2.000000']);
        $intake->refresh();
        $this->assertSame('1.000000', $intake->dose_quantity);
        $this->assertSame('Original label', $intake->supplement_name);
    }

    public function test_taken_time_rejects_a_nonexistent_profile_local_wall_time(): void
    {
        CarbonImmutable::setTestNow('2026-03-08 16:00:00 UTC');
        $owner = $this->createUser(timezone: 'America/New_York');
        $course = $this->createCourse($owner, attributes: [
            'starts_on' => '2026-03-08',
            'ends_on' => '2026-03-08',
        ]);

        try {
            app(SupplementIntakeService::class)->upsert(
                $this->occurrence($course, '2026-03-08'),
                $owner,
                [
                    'outcome' => SupplementIntake::OUTCOME_TAKEN,
                    'dose_quantity' => null,
                    'dose_display_unit' => null,
                    'taken_time' => '02:30',
                    'note' => null,
                ],
            );
            $this->fail('The missing DST wall time must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('taken_time', $exception->errors());
        }
    }
}
