<?php

namespace Tests\Feature\Finance;

use App\Services\RecurrenceMaterializer;
use Tests\Support\FinanceTestCase;

class FinanceOccurrenceApiTest extends FinanceTestCase
{
    public function test_occurrence_list_actual_skip_clear_and_foreign_boundary(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $other = $this->owner();
        $operation = $this->recurringOperation($owner, ['month_days' => [13, 14]]);
        app(RecurrenceMaterializer::class)->materialize($operation->recurringRule, '2026-08-13');
        $ids = $operation->recurringRule->occurrences()->orderBy('occurrence_date')->pluck('id');

        $this->actingAs($owner)->getJson('/api/finance/planned-occurrences?from=2026-08-01&to=2026-08-14')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'planned');
        $this->actingAs($other)->putJson("/api/finance/planned-occurrences/{$ids[0]}/outcome", ['outcome' => 'skipped'])
            ->assertNotFound();
        $this->actingAs($owner)->putJson("/api/finance/planned-occurrences/{$ids[0]}/outcome", ['outcome' => 'actual'])
            ->assertCreated()->assertJsonPath('data.status', 'actual');
        $this->actingAs($owner)->deleteJson("/api/finance/planned-occurrences/{$ids[0]}/outcome")
            ->assertConflict();
        $this->actingAs($owner)->putJson("/api/finance/planned-occurrences/{$ids[1]}/outcome", ['outcome' => 'skipped'])
            ->assertCreated()->assertJsonPath('data.status', 'skipped');
        $this->actingAs($owner)->deleteJson("/api/finance/planned-occurrences/{$ids[1]}/outcome")
            ->assertOk()->assertJsonPath('data.status', 'planned');
    }
}
