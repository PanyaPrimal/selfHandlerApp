<?php

namespace Tests\Feature\Body;

use App\Models\BodyMeasurement;
use App\Models\UserProfile;

class BodyMeasurementApiTest extends BodyTestCase
{
    public function test_an_observation_is_recorded_corrected_and_deleted(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->putJson('/api/body/measurements', [
            'metric' => 'body_mass',
            'measured_on' => self::TODAY,
            'value' => 82500,
        ])->assertOk()->assertJsonPath('data.value', '82500.0000');

        // Saving the same metric and day again is a correction, not a second row.
        $this->putJson('/api/body/measurements', [
            'metric' => 'body_mass',
            'measured_on' => self::TODAY,
            'value' => 82100,
        ])->assertOk()->assertJsonPath('data.value', '82100.0000');

        $this->assertSame(1, BodyMeasurement::query()->ownedBy($owner)->count());

        $id = BodyMeasurement::query()->ownedBy($owner)->value('id');
        $this->deleteJson("/api/body/measurements/{$id}")->assertNoContent();
        $this->assertSame(0, BodyMeasurement::query()->ownedBy($owner)->count());
    }

    public function test_history_is_ordered_by_date_regardless_of_entry_order(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        foreach (['2026-08-10', '2026-08-01', '2026-08-05'] as $date) {
            $this->putJson('/api/body/measurements', [
                'metric' => 'body_mass',
                'measured_on' => $date,
                'value' => 80000,
            ])->assertOk();
        }

        $this->getJson('/api/body/measurements?metric=body_mass')
            ->assertOk()
            ->assertJsonPath('data.0.measured_on', '2026-08-01')
            ->assertJsonPath('data.1.measured_on', '2026-08-05')
            ->assertJsonPath('data.2.measured_on', '2026-08-10')
            ->assertJsonPath('today', self::TODAY);
    }

    public function test_an_implausible_value_is_rejected_without_writing_anything(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        // 8.5 kg entered where grams were expected: a units mistake, not a body.
        $this->putJson('/api/body/measurements', [
            'metric' => 'body_mass',
            'measured_on' => self::TODAY,
            'value' => 8500,
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->putJson('/api/body/measurements', [
            'metric' => 'not_a_metric',
            'measured_on' => self::TODAY,
            'value' => 82000,
        ])->assertUnprocessable()->assertJsonValidationErrors('metric');

        $this->assertSame(0, BodyMeasurement::query()->count());
    }

    public function test_a_future_dated_measurement_is_rejected(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        // An observation is something that already happened; a future date would
        // also fall outside the default history window and simply vanish.
        $this->putJson('/api/body/measurements', [
            'metric' => 'body_mass',
            'measured_on' => '2026-08-13',
            'value' => 82000,
        ])->assertUnprocessable()->assertJsonValidationErrors('measured_on');

        $this->assertSame(0, BodyMeasurement::query()->count());
    }

    public function test_a_metric_without_history_is_absent_rather_than_zero(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);
        $this->measure($owner, 'body_mass', self::TODAY, 82000);

        $this->getJson('/api/body/measurements?metric=waist')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_measurements_stay_with_their_owner(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');

        $ownerRow = $this->measure($owner, 'body_mass', self::TODAY, 82000);
        $this->measure($other, 'body_mass', self::TODAY, 70000);

        $this->actingAs($other);

        $this->getJson('/api/body/measurements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', '70000.0000');

        $this->deleteJson("/api/body/measurements/{$ownerRow->id}")->assertNotFound();
        $this->assertModelExists($ownerRow);
    }

    public function test_recording_a_measurement_never_rewrites_the_profile_baseline(): void
    {
        $owner = $this->createUser();
        $owner->ensureProfile()->update(['weight_grams' => 90000]);
        $this->actingAs($owner);

        $this->putJson('/api/body/measurements', [
            'metric' => 'body_mass',
            'measured_on' => self::TODAY,
            'value' => 82000,
        ])->assertOk();

        // The Profile holds the user's stated baseline; the log holds what was
        // observed. Neither silently becomes the other.
        $this->assertSame(
            90000,
            (int) UserProfile::query()->where('user_id', $owner->id)->value('weight_grams'),
        );
    }
}
