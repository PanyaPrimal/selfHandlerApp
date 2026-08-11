<?php

namespace Tests\Feature\Profile;

use Carbon\CarbonImmutable;

class ProfileTimezoneBoundaryTest extends ProfileTestCase
{
    public function test_two_users_can_have_opposite_current_calendar_days(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 22:30:00 UTC');

        try {
            $kyiv = $this->createUser('kyiv@example.test');
            $newYork = $this->createUser('new-york@example.test');
            $this->createProfile($kyiv, ['timezone' => 'Europe/Kyiv']);
            $this->createProfile($newYork, ['timezone' => 'America/New_York']);

            $this->actingAs($kyiv)->getJson('/api/today')->assertOk()->assertJsonPath('date', '2026-08-11');
            $this->actingAs($newYork)->getJson('/api/today')->assertOk()->assertJsonPath('date', '2026-08-10');
            $this->getJson('/api/today?date=2026-03-29')->assertOk()->assertJsonPath('date', '2026-03-29');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
