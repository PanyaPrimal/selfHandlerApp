<?php

namespace App\Services\Integrations;

use App\Contracts\CalendarProvider;
use App\Data\Calendar\CalendarEventEnvelope;
use App\Data\Calendar\LocalCalendarProjection;
use App\Exceptions\CalendarIntegrationException;
use App\Models\ExternalCalendarEvent;
use App\Models\Integration;
use App\Models\SyncedItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class CalendarSyncService
{
    public function __construct(
        private readonly CalendarProviderRegistry $providers,
        private readonly CalendarLocalEventProjector $localEvents,
    ) {}

    /** @return array{imported:int,updated:int,removed:int,exported:int,deleted:int,conflicts:int,unchanged:int,completed_at:string} */
    public function sync(Integration $integration): array
    {
        $integration = Integration::query()->ownedBy($integration->user_id)->findOrFail($integration->id);
        if ($integration->status !== Integration::STATUS_ACTIVE || $integration->external_calendar_id === null) {
            throw new CalendarIntegrationException('calendar_connection_inactive', 409);
        }
        $lock = Cache::lock('calendar:integration:'.$integration->id, (int) config('integrations.sync.lock_seconds', 300));
        if (! $lock->get()) {
            throw new CalendarIntegrationException('calendar_sync_busy', 409);
        }

        try {
            return $this->run($integration);
        } finally {
            $lock->release();
        }
    }

    /** @return array{imported:int,updated:int,removed:int,exported:int,deleted:int,conflicts:int,unchanged:int,completed_at:string} */
    private function run(Integration $integration): array
    {
        $counts = ['imported' => 0, 'updated' => 0, 'removed' => 0, 'exported' => 0,
            'deleted' => 0, 'conflicts' => 0, 'unchanged' => 0];
        $now = CarbonImmutable::now();
        $user = $integration->user()->firstOrFail();
        $today = $now->setTimezone($user->calendarTimezone())->startOfDay();
        $fromDate = $today->subDays((int) config('integrations.sync.past_days', 90))->format('Y-m-d');
        $toDate = $today->addDays((int) config('integrations.sync.future_days', 365))->format('Y-m-d');
        $from = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $fromDate.' 00:00:00', $user->calendarTimezone())->utc();
        $to = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $toDate.' 00:00:00', $user->calendarTimezone())
            ->addDay()->utc();
        $projects = collect($this->localEvents->project($integration, $user, $fromDate, $toDate));
        $byStable = $projects->keyBy(fn (LocalCalendarProjection $projection): string => $projection->stableId);
        $provider = $this->providers->for($integration->provider);
        $integration->forceFill(['last_sync_at' => $now])->save();

        try {
            try {
                $page = $provider->pull($integration, $from, $to, $integration->sync_cursor);
            } catch (CalendarIntegrationException $exception) {
                if (! $exception->invalidCursor) {
                    throw $exception;
                }
                DB::transaction(function () use ($integration): void {
                    $ids = SyncedItem::query()->ownedBy($integration->user_id)
                        ->where('integration_id', $integration->id)
                        ->where('origin', SyncedItem::ORIGIN_PROVIDER)->pluck('local_id');
                    SyncedItem::query()->where('integration_id', $integration->id)
                        ->where('origin', SyncedItem::ORIGIN_PROVIDER)->delete();
                    ExternalCalendarEvent::query()->where('integration_id', $integration->id)
                        ->whereIn('id', $ids)->delete();
                    $integration->forceFill(['sync_cursor' => null])->save();
                });
                $page = $provider->pull($integration->fresh(), $from, $to, null);
            }
            $conflictedExternalHashes = $this->applyPull(
                $integration,
                $page->events,
                $page->nextCursor,
                $page->fullSnapshot,
                $byStable->all(),
                $counts,
            );
            $this->applyPush($integration, $projects->all(), $conflictedExternalHashes, $provider, $counts);
            $completed = CarbonImmutable::now();
            $integration->forceFill([
                'last_success_at' => $completed,
                'last_error_code' => null,
            ])->save();

            return [...$counts, 'completed_at' => $completed->toIso8601String()];
        } catch (CalendarIntegrationException $exception) {
            $integration->refresh()->forceFill([
                'status' => $exception->authenticationFailure ? Integration::STATUS_EXPIRED : $integration->status,
                'last_error_code' => $exception->errorCode,
            ])->save();
            throw $exception;
        } catch (Throwable $exception) {
            $integration->refresh()->forceFill(['last_error_code' => 'calendar_sync_failed'])->save();
            throw new CalendarIntegrationException('calendar_sync_failed');
        }
    }

    /**
     * @param  list<CalendarEventEnvelope>  $events
     * @param  array<string,LocalCalendarProjection>  $projectsByStable
     * @param  array<string,int>  $counts
     * @return array<string,true>
     */
    private function applyPull(
        Integration $integration,
        array $events,
        ?string $nextCursor,
        bool $fullSnapshot,
        array $projectsByStable,
        array &$counts,
    ): array {
        $seenProviderHashes = [];
        $conflicts = [];
        DB::transaction(function () use (
            $integration, $events, $nextCursor, $fullSnapshot, $projectsByStable,
            &$seenProviderHashes, &$conflicts, &$counts,
        ): void {
            foreach ($events as $event) {
                $hash = SyncedItem::externalHash($event->externalId);
                $mapping = SyncedItem::query()->where('integration_id', $integration->id)
                    ->where('external_id_hash', $hash)->first();
                if ($event->isTombstone()) {
                    if ($mapping?->origin === SyncedItem::ORIGIN_PROVIDER) {
                        ExternalCalendarEvent::query()->whereKey($mapping->local_id)
                            ->where('integration_id', $integration->id)->delete();
                        $mapping->delete();
                        $counts['removed']++;
                    } elseif ($mapping?->origin === SyncedItem::ORIGIN_SELFHANDLER) {
                        $counts['conflicts']++;
                        $conflicts[$hash] = true;
                    }

                    continue;
                }
                $recovered = $event->originKey !== null ? ($projectsByStable[$event->originKey] ?? null) : null;
                if (! $mapping && $recovered instanceof LocalCalendarProjection) {
                    $mapping = SyncedItem::query()->create([
                        'user_id' => $integration->user_id,
                        'integration_id' => $integration->id,
                        'origin' => SyncedItem::ORIGIN_SELFHANDLER,
                        'local_type' => $recovered->localType,
                        'local_id' => $recovered->localId,
                        'external_id' => $event->externalId,
                        'external_id_hash' => $hash,
                        'external_etag' => $event->etag,
                        'remote_updated_at' => $event->updatedAt,
                        'local_fingerprint' => $recovered->event->fingerprint(),
                        'last_synced_at' => now(),
                    ]);
                }
                if ($mapping?->origin === SyncedItem::ORIGIN_SELFHANDLER) {
                    if ($mapping->local_fingerprint !== $event->fingerprint()) {
                        $counts['conflicts']++;
                        $conflicts[$hash] = true;
                    }
                    $mapping->forceFill([
                        'external_etag' => $event->etag,
                        'remote_updated_at' => $event->updatedAt,
                    ])->save();

                    continue;
                }

                $seenProviderHashes[$hash] = true;
                $attributes = $this->eventAttributes($integration, $event, $hash);
                if ($mapping) {
                    $local = ExternalCalendarEvent::query()->whereKey($mapping->local_id)
                        ->where('integration_id', $integration->id)->first();
                    if ($local && $this->calendarEventChanged($local, $attributes)) {
                        $local->forceFill($attributes)->save();
                        $counts['updated']++;
                    } else {
                        $counts['unchanged']++;
                    }
                    $mapping->forceFill([
                        'external_id' => $event->externalId,
                        'external_etag' => $event->etag,
                        'remote_updated_at' => $event->updatedAt,
                        'last_synced_at' => now(),
                    ])->save();

                    continue;
                }
                $local = ExternalCalendarEvent::query()->create($attributes);
                SyncedItem::query()->create([
                    'user_id' => $integration->user_id,
                    'integration_id' => $integration->id,
                    'origin' => SyncedItem::ORIGIN_PROVIDER,
                    'local_type' => SyncedItem::LOCAL_EXTERNAL_EVENT,
                    'local_id' => $local->id,
                    'external_id' => $event->externalId,
                    'external_id_hash' => $hash,
                    'external_etag' => $event->etag,
                    'remote_updated_at' => $event->updatedAt,
                    'last_synced_at' => now(),
                ]);
                $counts['imported']++;
            }
            if ($fullSnapshot) {
                $stale = SyncedItem::query()->where('integration_id', $integration->id)
                    ->where('origin', SyncedItem::ORIGIN_PROVIDER)
                    ->when($seenProviderHashes !== [], fn ($query) => $query->whereNotIn('external_id_hash', array_keys($seenProviderHashes)))
                    ->get();
                foreach ($stale as $mapping) {
                    ExternalCalendarEvent::query()->whereKey($mapping->local_id)->delete();
                    $mapping->delete();
                    $counts['removed']++;
                }
            }
            $integration->forceFill(['sync_cursor' => $nextCursor])->save();
        });

        return $conflicts;
    }

    /**
     * @param  list<LocalCalendarProjection>  $projects
     * @param  array<string,true>  $conflictedExternalHashes
     * @param  array<string,int>  $counts
     */
    private function applyPush(
        Integration $integration,
        array $projects,
        array $conflictedExternalHashes,
        CalendarProvider $provider,
        array &$counts,
    ): void {
        $eligible = [];
        foreach ($projects as $project) {
            $eligible[$project->localKey()] = true;
            $mapping = SyncedItem::query()->where('integration_id', $integration->id)
                ->where('local_type', $project->localType)->where('local_id', $project->localId)->first();
            $fingerprint = $project->event->fingerprint();
            if ($mapping && $mapping->local_fingerprint === $fingerprint
                && ! isset($conflictedExternalHashes[$mapping->external_id_hash])) {
                continue;
            }
            $written = $provider->upsert(
                $integration,
                $project->event,
                $project->stableId,
                $mapping?->external_id,
                $mapping?->external_etag,
            );
            $hash = SyncedItem::externalHash($written->externalId);
            DB::transaction(function () use ($integration, $project, $mapping, $written, $hash, $fingerprint): void {
                $target = $mapping ?? new SyncedItem;
                $target->forceFill([
                    'user_id' => $integration->user_id,
                    'integration_id' => $integration->id,
                    'origin' => SyncedItem::ORIGIN_SELFHANDLER,
                    'local_type' => $project->localType,
                    'local_id' => $project->localId,
                    'external_id' => $written->externalId,
                    'external_id_hash' => $hash,
                    'external_etag' => $written->etag,
                    'remote_updated_at' => $written->updatedAt,
                    'local_fingerprint' => $fingerprint,
                    'last_synced_at' => now(),
                ])->save();
            });
            $counts['exported']++;
        }

        $mappings = SyncedItem::query()->where('integration_id', $integration->id)
            ->where('origin', SyncedItem::ORIGIN_SELFHANDLER)->get();
        foreach ($mappings as $mapping) {
            if (isset($eligible[$mapping->local_type.':'.$mapping->local_id])) {
                continue;
            }
            $provider->delete($integration, $mapping->external_id, $mapping->external_etag);
            $mapping->delete();
            $counts['deleted']++;
        }
    }

    /** @return array<string,mixed> */
    private function eventAttributes(
        Integration $integration,
        CalendarEventEnvelope $event,
        string $hash,
    ): array {
        return [
            'user_id' => $integration->user_id,
            'integration_id' => $integration->id,
            'external_id_hash' => $hash,
            'summary' => $event->summary,
            'starts_at' => $event->startsAt,
            'ends_at' => $event->endsAt,
            'start_date' => $event->startDate,
            'end_date' => $event->endDate,
            'is_all_day' => $event->allDay,
            'status' => $event->status,
        ];
    }

    /** @param array<string,mixed> $attributes */
    private function calendarEventChanged(ExternalCalendarEvent $event, array $attributes): bool
    {
        $current = [
            $event->summary,
            $event->starts_at?->toIso8601String(), $event->ends_at?->toIso8601String(),
            $event->start_date?->format('Y-m-d'), $event->end_date?->format('Y-m-d'),
            $event->is_all_day, $event->status,
        ];
        $next = [
            $attributes['summary'],
            $attributes['starts_at']?->toIso8601String(), $attributes['ends_at']?->toIso8601String(),
            $attributes['start_date'], $attributes['end_date'],
            $attributes['is_all_day'], $attributes['status'],
        ];

        return $current !== $next;
    }
}
