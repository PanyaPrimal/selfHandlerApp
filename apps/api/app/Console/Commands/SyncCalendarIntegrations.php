<?php

namespace App\Console\Commands;

use App\Exceptions\CalendarIntegrationException;
use App\Models\Integration;
use App\Services\Integrations\CalendarSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncCalendarIntegrations extends Command
{
    protected $signature = 'integrations:sync-calendars';

    protected $description = 'Synchronize active due owner calendar integrations';

    public function handle(CalendarSyncService $sync): int
    {
        $dueBefore = now()->subMinutes((int) config('integrations.sync.due_minutes', 15));
        $processed = 0;
        Integration::query()
            ->where('kind', Integration::KIND_CALENDAR)
            ->where('status', Integration::STATUS_ACTIVE)
            ->whereNotNull('external_calendar_id')
            ->where(fn ($query) => $query->whereNull('last_sync_at')->orWhere('last_sync_at', '<=', $dueBefore))
            ->orderBy('id')
            ->chunkById(50, function ($integrations) use ($sync, &$processed): void {
                foreach ($integrations as $integration) {
                    try {
                        $sync->sync($integration);
                        $processed++;
                    } catch (CalendarIntegrationException $exception) {
                        $this->warn("Calendar integration {$integration->id}: {$exception->errorCode}");
                    } catch (Throwable) {
                        $this->warn("Calendar integration {$integration->id}: calendar_sync_failed");
                    }
                }
            });
        $this->info("Calendar integrations synchronized: {$processed}");

        return self::SUCCESS;
    }
}
