<?php

namespace Tests\Unit\Notifications;

use App\Services\Notifications\QuietHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuietHoursTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function intervals(): iterable
    {
        yield 'same day inside' => ['2026-08-13 13:30:00 UTC', 'Europe/Kyiv', '14:00', '17:00', '2026-08-13 14:00:00 UTC'];
        yield 'same day outside' => ['2026-08-13 10:30:00 UTC', 'Europe/Kyiv', '14:00', '17:00', '2026-08-13 10:30:00 UTC'];
        yield 'cross midnight before midnight' => ['2026-08-13 21:30:00 UTC', 'Europe/Kyiv', '23:00', '08:00', '2026-08-14 05:00:00 UTC'];
        yield 'cross midnight after midnight' => ['2026-08-14 02:30:00 UTC', 'Europe/Kyiv', '23:00', '08:00', '2026-08-14 05:00:00 UTC'];
        yield 'end is allowed' => ['2026-08-14 05:00:00 UTC', 'Europe/Kyiv', '23:00', '08:00', '2026-08-14 05:00:00 UTC'];
    }

    #[DataProvider('intervals')]
    public function test_it_returns_the_first_allowed_utc_instant(
        string $instant,
        string $timezone,
        string $start,
        string $end,
        string $expected,
    ): void {
        $actual = app(QuietHours::class)->nextAllowedAt(
            CarbonImmutable::parse($instant),
            $timezone,
            true,
            $start,
            $end,
        );

        $this->assertSame($expected, $actual->format('Y-m-d H:i:s T'));
    }

    public function test_disabled_quiet_hours_never_move_the_instant(): void
    {
        $instant = CarbonImmutable::parse('2026-08-13 23:30:00 UTC');

        $this->assertTrue(app(QuietHours::class)
            ->nextAllowedAt($instant, 'UTC', false, '23:00', '08:00')
            ->equalTo($instant));
    }

    public function test_dst_uses_the_zone_rule_for_the_quiet_end(): void
    {
        // New York springs forward on 2026-03-08. 03:30 local is 07:30 UTC,
        // not the result of adding a fixed offset captured the night before.
        $actual = app(QuietHours::class)->nextAllowedAt(
            CarbonImmutable::parse('2026-03-08 06:30:00 UTC'),
            'America/New_York',
            true,
            '23:00',
            '03:30',
        );

        $this->assertSame('2026-03-08 07:30:00 UTC', $actual->format('Y-m-d H:i:s T'));
    }
}
