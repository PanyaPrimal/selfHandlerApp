<?php

namespace Tests\Feature\Planner;

use App\Services\Planner\SourceRegistry;
use App\Services\RecurrenceMaterializer;
use Tests\Support\FinanceTestCase;

class FinancePlannerIntegrationTest extends FinanceTestCase
{
    public function test_finance_source_is_registered_once_and_reads_one_snapshot_entry(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $operation = $this->recurringOperation($owner, [
            'name' => 'Rent', 'month_days' => [13], 'reminder_time' => '09:00',
        ]);
        app(RecurrenceMaterializer::class)->materialize($operation->recurringRule, '2026-08-13');

        $registry = app(SourceRegistry::class);
        $this->assertSame(1, count(array_filter($registry->names(), fn (string $name): bool => $name === 'finance')));
        $source = collect($registry->all())->first(fn ($candidate): bool => $candidate->name() === 'finance');
        $entry = $source->entriesFor($owner, '2026-08-13')[0];

        $this->assertSame('finance', $entry->source);
        $this->assertSame('Rent', $entry->title);
        $this->assertSame(['actualize', 'skip', 'reschedule'], $entry->actions);
        $this->assertStringStartsWith('/finance?tab=plans&month=2026-08', $entry->meta['action_url']);
        $this->assertStringContainsString('&occurrence=', $entry->meta['action_url']);
    }
}
