<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurringOperation;
use App\Models\RecurringRule;
use App\Models\User;
use App\Services\RecurrenceMaterializer;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class FinanceRecurringOperationService
{
    public function __construct(private readonly RecurrenceMaterializer $materializer) {}

    /** @param array<string,mixed> $data */
    public function create(User $user, array $data): FinanceRecurringOperation
    {
        return DB::transaction(function () use ($user, $data): FinanceRecurringOperation {
            [$attributes, $schedule] = $this->validate($user, $data, true);
            $operation = FinanceRecurringOperation::query()->create(['user_id' => $user->id, ...$attributes]);
            $rule = RecurringRule::query()->create([
                'user_id' => $user->id,
                'owner_type' => RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
                'owner_id' => $operation->id,
                'frequency' => RecurringRule::FREQUENCY_MONTHLY,
                'interval_count' => $schedule['interval_months'],
                'cycle_on_days' => null,
                'cycle_off_days' => null,
                'starts_on' => $schedule['starts_on'],
                'ends_on' => $schedule['ends_on'],
                'timezone' => $user->calendarTimezone(),
                'slot_time' => $schedule['reminder_time'],
            ]);
            $rule->syncMonthdays($schedule['month_days']);
            $this->materializer->materialize($rule->fresh(), null, true);

            return $this->hydrate($operation);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function update(
        FinanceRecurringOperation $operation,
        User $user,
        array $data,
    ): FinanceRecurringOperation {
        abort_unless($operation->isOwnedBy($user), 404);

        return DB::transaction(function () use ($operation, $user, $data): FinanceRecurringOperation {
            $locked = FinanceRecurringOperation::query()->ownedBy($user)->whereKey($operation->id)
                ->lockForUpdate()->firstOrFail();
            $rule = RecurringRule::query()->ownedBy($user)
                ->where('owner_type', RecurringRule::OWNER_FINANCE_RECURRING_OPERATION)
                ->where('owner_id', $locked->id)->lockForUpdate()->firstOrFail();
            $candidate = [
                'name' => $data['name'] ?? $locked->name,
                'direction' => $data['direction'] ?? $locked->direction,
                'account_id' => $data['account_id'] ?? $locked->account_id,
                'category_id' => $data['category_id'] ?? $locked->category_id,
                'amount' => $data['amount'] ?? $locked->amount,
                'mandatory' => $data['mandatory'] ?? $locked->is_mandatory,
                'starts_on' => $data['starts_on'] ?? $rule->starts_on->format('Y-m-d'),
                'ends_on' => array_key_exists('ends_on', $data)
                    ? $data['ends_on'] : $rule->ends_on?->format('Y-m-d'),
                'interval_months' => $data['interval_months'] ?? $rule->interval_count,
                'month_days' => $data['month_days'] ?? $rule->monthdays,
                'reminder_time' => array_key_exists('reminder_time', $data)
                    ? $data['reminder_time'] : ($rule->slot_time ? substr($rule->slot_time, 0, 5) : null),
            ];
            $semanticsChanged = collect(['name', 'direction', 'account_id', 'category_id', 'amount', 'mandatory'])
                ->contains(fn (string $key): bool => array_key_exists($key, $data));
            [$attributes, $schedule] = $this->validate($user, $candidate, $semanticsChanged);
            $archived = array_key_exists('archived', $data) ? (bool) $data['archived'] : $locked->is_archived;
            $active = array_key_exists('active', $data) ? (bool) $data['active'] : $locked->is_active;
            if ($archived) {
                $active = false;
            }
            $locked->fill([
                ...$attributes,
                'is_active' => $active,
                'is_archived' => $archived,
                'archived_at' => $archived ? ($locked->archived_at ?? now()) : null,
            ])->save();
            $rule->fill([
                'interval_count' => $schedule['interval_months'],
                'starts_on' => $schedule['starts_on'],
                'ends_on' => $schedule['ends_on'],
                'timezone' => $user->calendarTimezone(),
                'slot_time' => $schedule['reminder_time'],
            ])->save();
            $rule->syncMonthdays($schedule['month_days']);
            $this->materializer->materialize($rule->fresh(), null, $active && ! $archived);

            return $this->hydrate($locked);
        }, 3);
    }

    /** @param array<string,mixed> $data @return array{array<string,mixed>,array<string,mixed>} */
    private function validate(User $user, array $data, bool $requireActiveReferences): array
    {
        $name = trim((string) $data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('validation.required', ['attribute' => 'name'])]);
        }
        $direction = (string) $data['direction'];
        if (! in_array($direction, ['income', 'expense'], true)) {
            throw ValidationException::withMessages(['direction' => __('messages.finance_operation_direction_invalid')]);
        }
        $account = FinanceAccount::query()->ownedBy($user)->whereKey($data['account_id'])
            ->lockForUpdate()->firstOrFail();
        $category = FinanceCategory::query()->ownedBy($user)->whereKey($data['category_id'])
            ->lockForUpdate()->firstOrFail();
        if ($category->direction !== $direction
            || ($requireActiveReferences && ($account->archived_at !== null || $category->archived_at !== null))) {
            throw ValidationException::withMessages([
                'category_id' => __('messages.finance_operation_references_invalid'),
            ]);
        }
        if ($direction === 'income' && (bool) $data['mandatory']) {
            throw ValidationException::withMessages(['mandatory' => __('messages.finance_operation_mandatory_invalid')]);
        }
        try {
            $amount = Money::of((string) $data['amount'], $account->currency_code);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('messages.finance_money_invalid')]);
        }
        if (bccomp($amount->amount(), '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('messages.finance_positive_money')]);
        }

        $starts = CarbonImmutable::createFromFormat('!Y-m-d', (string) $data['starts_on']);
        $ends = $data['ends_on'] === null ? null
            : CarbonImmutable::createFromFormat('!Y-m-d', (string) $data['ends_on']);
        if (! $starts || ($ends && ($ends->lt($starts) || $ends->gt($starts->addYears(10))))) {
            throw ValidationException::withMessages(['ends_on' => __('messages.finance_operation_range_invalid')]);
        }
        $interval = (int) $data['interval_months'];
        $days = collect((array) $data['month_days'])->map(fn ($day): int => (int) $day)
            ->unique()->sort()->values()->all();
        if ($interval < 1 || $interval > 12 || $days === [] || count($days) > 10
            || collect($days)->contains(fn (int $day): bool => $day < 1 || $day > 31)) {
            throw ValidationException::withMessages(['month_days' => __('messages.finance_operation_rule_invalid')]);
        }

        return [[
            'name' => $name,
            'direction' => $direction,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => $amount->amount(),
            'currency_code' => $account->currency_code,
            'is_mandatory' => (bool) $data['mandatory'],
            'is_active' => true,
            'is_archived' => false,
            'archived_at' => null,
        ], [
            'starts_on' => $starts->toDateString(),
            'ends_on' => $ends?->toDateString(),
            'interval_months' => $interval,
            'month_days' => $days,
            'reminder_time' => $data['reminder_time'],
        ]];
    }

    private function hydrate(FinanceRecurringOperation $operation): FinanceRecurringOperation
    {
        return $operation->fresh()->load(['account', 'category', 'recurringRule.ruleMonthdays']);
    }
}
