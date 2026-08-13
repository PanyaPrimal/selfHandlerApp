<?php

namespace Tests\Feature\Supplements;

use App\Models\InAppNotification;
use App\Models\SupplementRestockProposal;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEscalator;
use App\Services\Notifications\NotificationSourceSynchronizer;
use App\Services\SupplementIntakeService;
use App\Services\SupplementRestockProposalService;
use Carbon\CarbonImmutable;

class SupplementNotificationIntegrationTest extends SupplementTestCase
{
    public function test_timed_intake_is_localized_escalates_three_times_and_stops_on_fact(): void
    {
        $owner = $this->createUser();
        $owner->ensureProfile()->update(['locale' => 'ru-UA']);
        $course = $this->createCourse($owner);
        $occurrence = $this->occurrence($course);
        $sync = app(NotificationSourceSynchronizer::class);

        $this->assertTrue($owner->ensureNotificationSettings()->categoryEnabled('supplement'));
        $this->assertSame(1, $sync->synchronize($owner, CarbonImmutable::now()));
        $this->assertSame(0, $sync->synchronize($owner, CarbonImmutable::now()));

        $notification = InAppNotification::query()->sole();
        $this->assertSame(InAppNotification::TYPE_SUPPLEMENT_INTAKE, $notification->type);
        $this->assertSame(InAppNotification::CATEGORY_SUPPLEMENT, $notification->category);
        $this->assertSame(3, $notification->max_escalations);
        $this->assertSame('/supplements?date=2026-08-13&course='.$course->id.'&slot=morning', $notification->action_url);

        $this->assertSame(1, app(NotificationDispatcher::class)->dispatchForUser($owner, CarbonImmutable::now()));
        $this->assertSame('Напоминание о приёме', $notification->fresh()->title);
        $this->assertSame(1, app(NotificationEscalator::class)->scheduleForUser($owner, CarbonImmutable::now()->addMinutes(30)));

        app(SupplementIntakeService::class)->upsert($occurrence, $owner, [
            'outcome' => 'skipped', 'dose_quantity' => null, 'dose_display_unit' => null,
            'taken_time' => null, 'note' => null,
        ]);
        $sync->synchronize($owner, CarbonImmutable::now());

        $this->assertSame(0, InAppNotification::query()->whereIn('status', InAppNotification::ACTIVE_STATUSES)->count());
        $this->assertSame(0, app(NotificationEscalator::class)->scheduleForUser($owner, CarbonImmutable::now()->addHour()));
    }

    public function test_open_restock_proposal_delivers_once_without_escalation_and_closes_with_proposal(): void
    {
        $owner = $this->createUser();
        $supplement = $this->createSupplement($owner);
        $proposal = SupplementRestockProposal::create([
            'user_id' => $owner->id,
            'supplement_id' => $supplement->id,
            'active_supplement_id' => $supplement->id,
            'shortage_fingerprint' => hash('sha256', 'shortage'),
            'forecast_runout_on' => self::TODAY,
            'needed_by' => self::TODAY,
            'suggested_quantity' => '30',
            'stock_unit' => 'piece',
            'status' => SupplementRestockProposal::STATUS_OPEN,
        ]);
        $sync = app(NotificationSourceSynchronizer::class);

        $this->assertSame(1, $sync->synchronize($owner, CarbonImmutable::now()));
        $notification = InAppNotification::query()->sole();
        $this->assertSame(InAppNotification::SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL, $notification->source_type);
        $this->assertSame(InAppNotification::TYPE_SUPPLEMENT_RESTOCK, $notification->type);
        $this->assertSame(0, $notification->max_escalations);
        $this->assertSame('/supplements?restock='.$proposal->id, $notification->action_url);

        $this->assertSame(1, app(NotificationDispatcher::class)->dispatchForUser($owner, CarbonImmutable::now()));
        $this->assertNull($notification->fresh()->next_escalation_at);

        app(SupplementRestockProposalService::class)->dismiss($proposal);
        $sync->synchronize($owner, CarbonImmutable::now());
        $this->assertSame(InAppNotification::STATUS_CANCELLED, $notification->fresh()->status);
        $this->assertDatabaseCount('recurring_rules', 0);
    }
}
