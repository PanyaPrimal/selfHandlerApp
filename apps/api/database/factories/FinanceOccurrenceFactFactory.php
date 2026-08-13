<?php

namespace Database\Factories;

use App\Models\FinanceOccurrenceDetail;
use App\Models\FinanceOccurrenceFact;
use App\Models\FinanceTransactionGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceOccurrenceFact> */
class FinanceOccurrenceFactFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (FinanceOccurrenceFact $fact): void {
            $fact->plannedOccurrence->forceFill([
                'status' => $fact->outcome === FinanceOccurrenceFact::OUTCOME_SKIPPED ? 'skipped' : 'done',
                'finance_occurrence_fact_id' => $fact->id,
            ])->save();
        });
    }

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'planned_occurrence_id' => fn (array $attributes): int => FinanceOccurrenceDetail::factory()
                ->create(['user_id' => $attributes['user_id']])->planned_occurrence_id,
            'outcome' => FinanceOccurrenceFact::OUTCOME_SKIPPED,
            'transaction_group_id' => null,
            'occurred_on' => null,
        ];
    }

    public function skipped(): static
    {
        return $this->state([
            'outcome' => FinanceOccurrenceFact::OUTCOME_SKIPPED,
            'transaction_group_id' => null,
            'occurred_on' => null,
        ]);
    }

    public function actual(): static
    {
        return $this->state([
            'outcome' => FinanceOccurrenceFact::OUTCOME_ACTUAL,
            'occurred_on' => now()->toDateString(),
        ])->afterMaking(function (FinanceOccurrenceFact $fact): void {
            if ($fact->transaction_group_id === null) {
                $fact->transaction_group_id = FinanceTransactionGroup::factory()->create([
                    'user_id' => $fact->user_id,
                    'kind' => 'expense',
                    'occurred_on' => now()->toDateString(),
                ])->id;
            }
        });
    }
}
