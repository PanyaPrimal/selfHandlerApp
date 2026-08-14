<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\ExternalCalendarEvent;
use App\Models\Integration;
use App\Models\User;
use App\Support\PlannerEntry;
use Carbon\CarbonImmutable;

class ExternalCalendarSource implements SchedulableSource
{
    public function name(): string
    {
        return 'external_calendar';
    }

    public function entriesFor(User $user, string $date): array
    {
        $timezone = $user->calendarTimezone();
        $dayStart = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', $timezone);
        $dayEnd = $dayStart->addDay();

        return ExternalCalendarEvent::query()->ownedBy($user)
            ->where(function ($query) use ($date, $dayStart, $dayEnd): void {
                $query->where(function ($allDay) use ($date): void {
                    $allDay->where('is_all_day', true)
                        ->where('start_date', '<=', $date)
                        ->where('end_date', '>', $date);
                })->orWhere(function ($timed) use ($dayStart, $dayEnd): void {
                    $timed->where('is_all_day', false)
                        ->where('starts_at', '<', $dayEnd->utc())
                        ->where('ends_at', '>', $dayStart->utc());
                });
            })
            ->with('integration')
            ->orderBy('starts_at')->orderBy('start_date')->orderBy('id')
            ->get()
            ->filter(fn (ExternalCalendarEvent $event): bool => $event->integration !== null)
            ->map(function (ExternalCalendarEvent $event) use ($dayStart, $dayEnd, $timezone): PlannerEntry {
                $integration = $event->integration;
                $settings = Integration::normalizeSettings($integration->settings);
                $visibleTitle = $settings['import_detail'] === Integration::IMPORT_TITLE
                    ? ($event->summary ?: __('messages.calendar_busy'))
                    : __('messages.calendar_busy');
                $localStart = $event->starts_at?->setTimezone($timezone);
                $localEnd = $event->ends_at?->setTimezone($timezone);
                $visibleStart = $localStart?->greaterThan($dayStart) ? $localStart : $dayStart;
                $visibleEnd = $localEnd?->lessThan($dayEnd) ? $localEnd : $dayEnd;

                return new PlannerEntry(
                    source: $this->name(),
                    sourceId: (int) $event->id,
                    title: $visibleTitle,
                    time: $event->is_all_day ? null : $visibleStart?->format('H:i'),
                    status: 'busy',
                    actions: [],
                    meta: [
                        'all_day' => $event->is_all_day,
                        'ends_at' => $event->is_all_day ? null : $visibleEnd?->format('H:i'),
                        'spans_days' => $event->is_all_day
                            ? $event->start_date->diffInDays($event->end_date) > 1
                            : ! $localStart?->isSameDay($localEnd),
                        'provider' => $integration->provider,
                        'calendar_name' => $integration->external_calendar_name,
                        'read_only' => true,
                    ],
                );
            })->values()->all();
    }
}
