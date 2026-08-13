<?php

namespace Database\Factories;

use App\Models\FinanceOccurrenceDetail;
use App\Models\FinanceRecurringOperation;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceOccurrenceDetail> */
class FinanceOccurrenceDetailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'finance_recurring_operation_id' => fn (array $attributes): int => FinanceRecurringOperation::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'planned_occurrence_id' => function (array $attributes): int {
                $operation = FinanceRecurringOperation::query()->findOrFail(
                    $attributes['finance_recurring_operation_id'],
                );
                $rule = RecurringRule::factory()->create([
                    'user_id' => $attributes['user_id'],
                    'owner_type' => RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
                    'owner_id' => $operation->id,
                    'frequency' => RecurringRule::FREQUENCY_MONTHLY,
                ]);

                return PlannedOccurrence::query()->create([
                    'user_id' => $attributes['user_id'],
                    'recurring_rule_id' => $rule->id,
                    'occurrence_date' => now()->toDateString(),
                    'slot' => '',
                    'status' => PlannedOccurrence::STATUS_PLANNED,
                ])->id;
            },
            'operation_name' => fn (array $attributes): string => FinanceRecurringOperation::query()
                ->findOrFail($attributes['finance_recurring_operation_id'])->name,
            'direction' => fn (array $attributes): string => FinanceRecurringOperation::query()
                ->findOrFail($attributes['finance_recurring_operation_id'])->direction,
            'account_id' => fn (array $attributes): int => FinanceRecurringOperation::query()
                ->findOrFail($attributes['finance_recurring_operation_id'])->account_id,
            'category_id' => fn (array $attributes): int => FinanceRecurringOperation::query()
                ->findOrFail($attributes['finance_recurring_operation_id'])->category_id,
            'amount' => fn (array $attributes): string => FinanceRecurringOperation::query()
                ->findOrFail($attributes['finance_recurring_operation_id'])->amount,
            'currency_code' => fn (array $attributes): string => FinanceRecurringOperation::query()
                ->findOrFail($attributes['finance_recurring_operation_id'])->currency_code,
            'is_mandatory' => fn (array $attributes): bool => FinanceRecurringOperation::query()
                ->findOrFail($attributes['finance_recurring_operation_id'])->is_mandatory,
        ];
    }
}
