<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceLedgerEntry;
use App\Models\FinanceTransactionGroup;
use App\Models\User;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceLedgerService
{
    public function __construct(private readonly FinanceIdempotency $idempotency) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{FinanceTransactionGroup, bool}
     */
    public function postActual(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $account = $this->activeAccount($user, (int) $data['account_id'], true);
            $category = FinanceCategory::query()->ownedBy($user)
                ->whereKey($data['category_id'])->lockForUpdate()->firstOrFail();
            if ($category->archived_at !== null || $category->direction !== $data['kind']) {
                throw ValidationException::withMessages([
                    'category_id' => __('messages.finance_category_actual_invalid'),
                ]);
            }
            $amount = $this->positiveMoney((string) $data['amount'], $account->currency_code, 'amount');
            $payload = [
                'kind' => $data['kind'],
                'account_id' => $account->id,
                'category_id' => $category->id,
                'amount' => $amount->amount(),
                'occurred_on' => $data['occurred_on'],
                'note' => $this->nullableTrim($data['note'] ?? null),
                'tag' => $this->nullableTrim($data['tag'] ?? null),
            ];
            $existing = $this->idempotency->existing($user, $data['idempotency_key'], $payload);
            if ($existing) {
                return [$this->hydrate($existing), false];
            }

            $group = $this->group($user, $data['idempotency_key'], $payload, [
                'kind' => $data['kind'],
                'occurred_on' => $data['occurred_on'],
                'note' => $payload['note'],
                'tag' => $payload['tag'],
            ]);
            $this->entry(
                $user,
                $group,
                $account,
                $category,
                'primary',
                $data['kind'] === 'expense' ? $amount->negate() : $amount,
            );

            return [$this->hydrate($group), true];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{FinanceTransactionGroup, bool}
     */
    public function transfer(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            if ((int) $data['source_account_id'] === (int) $data['destination_account_id']) {
                throw ValidationException::withMessages([
                    'destination_account_id' => __('messages.finance_transfer_accounts_distinct'),
                ]);
            }
            $ids = [(int) $data['source_account_id'], (int) $data['destination_account_id']];
            sort($ids);
            $accounts = FinanceAccount::query()->ownedBy($user)->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $accounts->get((int) $data['source_account_id']);
            $destination = $accounts->get((int) $data['destination_account_id']);
            if (! $source || ! $destination) {
                abort(404);
            }
            if ($source->archived_at !== null || $destination->archived_at !== null) {
                throw ValidationException::withMessages([
                    'account_id' => __('messages.finance_account_archived'),
                ]);
            }
            $sourceAmount = $this->positiveMoney((string) $data['source_amount'], $source->currency_code, 'source_amount');
            $destinationAmount = $this->positiveMoney(
                (string) $data['destination_amount'],
                $destination->currency_code,
                'destination_amount',
            );
            if ($source->currency_code === $destination->currency_code
                && bccomp($sourceAmount->amount(), $destinationAmount->amount(), 4) !== 0) {
                throw ValidationException::withMessages([
                    'destination_amount' => __('messages.finance_transfer_same_currency_amount'),
                ]);
            }

            $payload = [
                'source_account_id' => $source->id,
                'destination_account_id' => $destination->id,
                'source_amount' => $sourceAmount->amount(),
                'destination_amount' => $destinationAmount->amount(),
                'occurred_on' => $data['occurred_on'],
                'note' => $this->nullableTrim($data['note'] ?? null),
                'tag' => $this->nullableTrim($data['tag'] ?? null),
            ];
            $existing = $this->idempotency->existing($user, $data['idempotency_key'], $payload);
            if ($existing) {
                return [$this->hydrate($existing), false];
            }

            $crossCurrency = $source->currency_code !== $destination->currency_code;
            $group = $this->group($user, $data['idempotency_key'], $payload, [
                'kind' => 'transfer',
                'occurred_on' => $data['occurred_on'],
                'note' => $payload['note'],
                'tag' => $payload['tag'],
                'fx_from_currency' => $crossCurrency ? $source->currency_code : null,
                'fx_to_currency' => $crossCurrency ? $destination->currency_code : null,
                'effective_rate' => $crossCurrency
                    ? $this->divideRound($destinationAmount->amount(), $sourceAmount->amount(), 12)
                    : null,
            ]);
            $this->entry($user, $group, $source, null, 'source', $sourceAmount->negate());
            $this->entry($user, $group, $destination, null, 'destination', $destinationAmount);

            return [$this->hydrate($group), true];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{FinanceTransactionGroup, bool}
     */
    public function reverse(User $user, FinanceTransactionGroup $original, array $data): array
    {
        abort_unless($original->isOwnedBy($user), 404);

        return DB::transaction(function () use ($user, $original, $data): array {
            $locked = FinanceTransactionGroup::query()->ownedBy($user)->whereKey($original->id)
                ->lockForUpdate()->firstOrFail();
            $payload = [
                'reverses_id' => $locked->public_id,
                'reason' => trim($data['reason']),
            ];
            $existing = $this->idempotency->existing($user, $data['idempotency_key'], $payload);
            if ($existing) {
                return [$this->hydrate($existing), false];
            }
            if ($locked->reverses_group_id !== null || $locked->reversedBy()->exists()) {
                throw new HttpResponseException(response()->json([
                    'message' => __('messages.finance_reversal_conflict'),
                ], 409));
            }

            $group = $this->group($user, $data['idempotency_key'], $payload, [
                'kind' => $locked->kind,
                'occurred_on' => CarbonImmutable::now($user->calendarTimezone())->toDateString(),
                'note' => $locked->note,
                'tag' => $locked->tag,
                'reverses_group_id' => $locked->id,
                'reversal_reason' => trim($data['reason']),
                'fx_from_currency' => $locked->fx_from_currency,
                'fx_to_currency' => $locked->fx_to_currency,
                'effective_rate' => $locked->effective_rate,
            ]);
            $locked->load('entries');
            foreach ($locked->entries as $originalEntry) {
                $account = $this->activeOrArchivedAccount($user, $originalEntry->account_id);
                $category = $originalEntry->category_id
                    ? FinanceCategory::query()->ownedBy($user)->findOrFail($originalEntry->category_id)
                    : null;
                $this->entry(
                    $user,
                    $group,
                    $account,
                    $category,
                    $originalEntry->role,
                    Money::of((string) $originalEntry->delta_amount, $originalEntry->currency_code)->negate(),
                );
            }

            return [$this->hydrate($group), true];
        }, 3);
    }

    private function activeAccount(User $user, int $id, bool $lock = false): FinanceAccount
    {
        $query = FinanceAccount::query()->ownedBy($user)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $account = $query->firstOrFail();
        if ($account->archived_at !== null) {
            throw ValidationException::withMessages(['account_id' => __('messages.finance_account_archived')]);
        }

        return $account;
    }

    private function activeOrArchivedAccount(User $user, int $id): FinanceAccount
    {
        return FinanceAccount::query()->ownedBy($user)->findOrFail($id);
    }

    private function positiveMoney(string $amount, string $currency, string $field): Money
    {
        $money = Money::of($amount, $currency);
        if (bccomp($money->amount(), '0', 4) <= 0) {
            throw ValidationException::withMessages([$field => __('messages.finance_positive_money')]);
        }

        return $money;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $attributes */
    private function group(User $user, string $key, array $payload, array $attributes): FinanceTransactionGroup
    {
        return FinanceTransactionGroup::query()->create($attributes + [
            'user_id' => $user->id,
            'public_id' => (string) Str::uuid(),
            'idempotency_key' => $key,
            'payload_hash' => $this->idempotency->hash($payload),
        ]);
    }

    private function entry(
        User $user,
        FinanceTransactionGroup $group,
        FinanceAccount $account,
        ?FinanceCategory $category,
        string $role,
        Money $delta,
    ): FinanceLedgerEntry {
        return FinanceLedgerEntry::query()->create([
            'user_id' => $user->id,
            'transaction_group_id' => $group->id,
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'role' => $role,
            'delta_amount' => $delta->amount(),
            'currency_code' => $account->currency_code,
        ]);
    }

    private function hydrate(FinanceTransactionGroup $group): FinanceTransactionGroup
    {
        return $group->fresh()->load(['entries.account', 'entries.category', 'reverses', 'reversedBy']);
    }

    private function nullableTrim(mixed $value): ?string
    {
        return $value === null ? null : trim((string) $value);
    }

    private function divideRound(string $numerator, string $denominator, int $scale): string
    {
        $value = bcdiv($numerator, $denominator, $scale + 4);
        $increment = '0.'.str_repeat('0', $scale).'5';

        return bcadd(bcadd($value, $increment, $scale + 4), '0', $scale);
    }
}
