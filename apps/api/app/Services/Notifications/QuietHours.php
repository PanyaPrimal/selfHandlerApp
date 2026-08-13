<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;

class QuietHours
{
    public function nextAllowedAt(
        CarbonImmutable $instant,
        string $timezone,
        bool $enabled,
        string $startsAt,
        string $endsAt,
    ): CarbonImmutable {
        if (! $enabled) {
            return $instant;
        }

        $local = $instant->setTimezone($timezone);
        $date = $local->toDateString();
        $start = CarbonImmutable::parse("{$date} {$startsAt}", $timezone);
        $end = CarbonImmutable::parse("{$date} {$endsAt}", $timezone);

        if ($start->lessThan($end)) {
            if ($local->greaterThanOrEqualTo($start) && $local->lessThan($end)) {
                return $end->utc();
            }

            return $instant;
        }

        // Cross-midnight: the late half ends tomorrow; the early half began
        // yesterday and ends today.
        if ($local->greaterThanOrEqualTo($start)) {
            return $end->addDay()->utc();
        }

        if ($local->lessThan($end)) {
            return $end->utc();
        }

        return $instant;
    }
}
