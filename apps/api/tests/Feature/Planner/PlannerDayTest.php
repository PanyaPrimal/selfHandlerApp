<?php

namespace Tests\Feature\Planner;

use App\Models\Item;
use App\Models\RoutineLog;
use Illuminate\Support\Facades\DB;

class PlannerDayTest extends PlannerTestCase
{
    public function test_one_day_shows_every_source_once_in_one_order(): void
    {
        $owner = $this->createUser();
        $this->createRoutine($owner, 'Morning walk', ['preferred_time' => '07:30']);
        $this->createItem($owner, 'Order tiles', self::TODAY);
        $this->createBlock($owner, ['title' => 'Dentist', 'starts_at' => '14:00', 'ends_at' => '15:00']);
        $this->createBlock($owner, ['title' => 'Read', 'starts_at' => null]);

        $this->actingAs($owner);

        $response = $this->getJson('/api/planner/day')->assertOk();

        $response
            ->assertJsonPath('date', self::TODAY)
            ->assertJsonPath('today', self::TODAY)
            ->assertJsonCount(4, 'entries')
            // Timed first in time order, then untimed by title.
            ->assertJsonPath('entries.0.title', 'Morning walk')
            ->assertJsonPath('entries.0.source', 'routine')
            ->assertJsonPath('entries.0.time', '07:30')
            ->assertJsonPath('entries.1.title', 'Dentist')
            ->assertJsonPath('entries.1.source', 'time_block')
            ->assertJsonPath('entries.2.title', 'Order tiles')
            ->assertJsonPath('entries.2.source', 'storage')
            ->assertJsonPath('entries.3.title', 'Read');

        $this->assertSame(
            ['routine', 'sleep', 'habit', 'storage', 'time_block'],
            $response->json('sources'),
        );
    }

    public function test_an_empty_day_is_empty_rather_than_missing(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->getJson('/api/planner/day?date=2026-08-20')
            ->assertOk()
            ->assertJsonCount(0, 'entries')
            ->assertJsonPath('date', '2026-08-20');
    }

    public function test_a_day_beyond_the_window_says_so(): void
    {
        $owner = $this->createUser();
        $this->createRoutine($owner);
        $this->actingAs($owner);

        // The window reaches 90 days out; well past that is not "nothing planned".
        $this->getJson('/api/planner/day?date=2027-06-01')
            ->assertOk()
            ->assertJsonPath('window.beyond', true)
            ->assertJsonCount(0, 'entries');

        $this->getJson('/api/planner/day?date='.self::TOMORROW)
            ->assertOk()
            ->assertJsonPath('window.beyond', false);
    }

    public function test_the_default_day_is_the_users_own_today(): void
    {
        $auckland = $this->createUser('auckland@example.test', 'Pacific/Auckland');
        $this->actingAs($auckland);

        // 2026-08-12 09:00 UTC is already the 12th in Kyiv but the 12th/13th
        // elsewhere; whatever it is, it must come from the profile zone.
        $this->getJson('/api/planner/day')
            ->assertOk()
            ->assertJsonPath('date', '2026-08-12')
            ->assertJsonPath('today', '2026-08-12');
    }

    public function test_a_day_never_shows_another_account(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');

        $this->createRoutine($owner, 'Owner routine');
        $this->createItem($owner, 'Owner task', self::TODAY);
        $this->createBlock($owner, ['title' => 'Owner block']);
        $this->createBlock($other, ['title' => 'Other block']);

        $this->actingAs($other);

        $response = $this->getJson('/api/planner/day')->assertOk();

        $titles = array_column($response->json('entries'), 'title');

        $this->assertSame(['Other block'], $titles);
    }

    public function test_a_completed_day_offers_no_planner_actions(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $this->actingAs($owner);

        $this->getJson('/api/planner/day')
            ->assertOk()
            ->assertJsonPath('entries.0.actions', ['skip', 'reschedule']);

        $this->putJson("/api/routines/{$routine->id}/logs/".self::TODAY, ['status' => 'done'])->assertOk();

        // What already happened cannot be moved or skipped.
        $this->getJson('/api/planner/day')
            ->assertOk()
            ->assertJsonPath('entries.0.actions', [])
            ->assertJsonPath('entries.0.status', 'done');
    }

    public function test_a_closed_storage_item_leaves_the_day(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner, 'Order tiles', self::TODAY);
        $this->actingAs($owner);

        $this->getJson('/api/planner/day')->assertOk()->assertJsonCount(1, 'entries');

        $this->patchJson("/api/storage/items/{$item->id}", ['status' => Item::STATUS_DONE])->assertOk();

        $this->getJson('/api/planner/day')->assertOk()->assertJsonCount(0, 'entries');
    }

    public function test_reading_a_busy_day_uses_a_bounded_query_count(): void
    {
        $owner = $this->createUser();

        for ($index = 0; $index < 15; $index++) {
            $this->createRoutine($owner, "Routine {$index}");
            $this->createItem($owner, "Task {$index}", self::TODAY);
            $this->createBlock($owner, ['title' => "Block {$index}"]);
        }

        $this->actingAs($owner);

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $this->getJson('/api/planner/day')->assertOk()->assertJsonCount(45, 'entries');
            $queries = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }

        // Roughly one query per source plus the shared lookups. The point is that
        // it does not grow with the 45 entries on the day.
        $this->assertLessThanOrEqual(18, $queries);
    }

    public function test_planner_stores_nothing_belonging_to_another_module(): void
    {
        $owner = $this->createUser();
        $this->createRoutine($owner);
        $this->createItem($owner, 'Order tiles', self::TODAY);
        $this->actingAs($owner);

        $logsBefore = RoutineLog::query()->count();
        $itemsBefore = Item::query()->count();

        $this->getJson('/api/planner/day')->assertOk();

        // Reading a day is a read. Nothing is copied into Planner, and nothing
        // is written back into the modules it displays.
        $this->assertSame($logsBefore, RoutineLog::query()->count());
        $this->assertSame($itemsBefore, Item::query()->count());
        $this->assertSame(0, DB::table('time_blocks')->count());
    }
}
