<?php

namespace Tests\Unit\Supplements;

use App\Models\RecurringRule;
use App\Models\RecurringRuleSlot;
use App\Models\User;
use App\Services\RecurrenceMaterializer;
use App\Services\RecurringRuleExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplementCourseRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_interval_and_cycle_filters_are_anchored_to_start_date(): void
    {
        $rule = $this->rule([
            'frequency' => 'daily',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-20',
            'interval_count' => 2,
            'cycle_on_days' => 5,
            'cycle_off_days' => 3,
        ]);

        $this->assertSame(
            ['2026-08-01', '2026-08-03', '2026-08-05', '2026-08-09', '2026-08-11',
                '2026-08-13', '2026-08-17', '2026-08-19'],
            app(RecurringRuleExpander::class)->datesBetween($rule, '2026-08-01', '2026-08-20'),
        );
    }

    public function test_multiple_slots_materialize_once_per_date_and_legacy_rules_keep_empty_slot(): void
    {
        $rule = $this->rule(['starts_on' => '2026-08-13', 'ends_on' => '2026-08-13']);
        foreach ([['morning', '08:00', 0], ['evening', '20:00', 1]] as [$slot, $time, $order]) {
            RecurringRuleSlot::create([
                'user_id' => $rule->user_id,
                'recurring_rule_id' => $rule->id,
                'slot' => $slot,
                'occurrence_time' => $time,
                'sort_order' => $order,
            ]);
        }

        app(RecurrenceMaterializer::class)->materialize($rule->fresh('ruleSlots'), '2026-08-13');
        app(RecurrenceMaterializer::class)->materialize($rule->fresh('ruleSlots'), '2026-08-13');

        $this->assertDatabaseCount('planned_occurrences', 2);
        $this->assertDatabaseHas('planned_occurrences', ['slot' => 'morning', 'occurrence_time' => '08:00']);
        $this->assertDatabaseHas('planned_occurrences', ['slot' => 'evening', 'occurrence_time' => '20:00']);

        $legacy = $this->rule([
            'owner_id' => 2, 'starts_on' => '2026-08-13', 'ends_on' => '2026-08-13',
            'slot_time' => '09:30',
        ]);
        app(RecurrenceMaterializer::class)->materialize($legacy, '2026-08-13');
        $this->assertDatabaseHas('planned_occurrences', [
            'recurring_rule_id' => $legacy->id, 'slot' => '', 'occurrence_time' => '09:30',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function rule(array $attributes = []): RecurringRule
    {
        $user = User::factory()->create();

        return RecurringRule::create([
            'user_id' => $user->id,
            'owner_type' => RecurringRule::OWNER_ROUTINE,
            'owner_id' => $attributes['owner_id'] ?? 1,
            'frequency' => RecurringRule::FREQUENCY_DAILY,
            'starts_on' => null,
            'ends_on' => null,
            'timezone' => 'UTC',
            'slot_time' => null,
            ...$attributes,
        ]);
    }
}
