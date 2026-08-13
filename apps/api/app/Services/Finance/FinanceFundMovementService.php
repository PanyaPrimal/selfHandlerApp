<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceFundMovement;
use App\Models\FinanceFundOccurrenceFact;
use App\Models\FinanceSavingFund;
use App\Models\User;
use App\Services\OccurrenceFactSynchronizer;
use App\ValueObjects\Money;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceFundMovementService
{
    public function __construct(
        private readonly FinanceBalanceService $balances,
        private readonly FinanceIdempotency $idempotency,
        private readonly FinanceLedgerService $ledger,
        private readonly OccurrenceFactSynchronizer $occurrences,
    ) {}

    /** @param array<string, mixed> $data @return array{FinanceFundMovement, bool} */
    public function move(User $user, FinanceSavingFund $fund, array $data): array
    {
        abort_unless($fund->isOwnedBy($user), 404);

        return DB::transaction(function () use ($user, $fund, $data): array {
            $locked = FinanceSavingFund::query()->ownedBy($user)->whereKey($fund->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active || $locked->is_archived || $locked->spent_at !== null) {
                throw ValidationException::withMessages(['fund' => __('messages.finance_fund_inactive')]);
            }
            if (($data['action'] ?? null) === 'reverse') {
                return $this->reverse($user, $locked, $data);
            }
            if (! in_array($data['action'] ?? null, ['top_up', 'draw_down'], true)) {
                throw ValidationException::withMessages(['action' => __('messages.finance_fund_movement_invalid')]);
            }
            $money = Money::of((string) $data['amount'], $locked->currency_code);
            if (bccomp($money->amount(), '0', 4) <= 0) {
                throw ValidationException::withMessages(['amount' => __('messages.finance_positive_money')]);
            }
            $payload = [
                'fund_id' => $locked->id, 'action' => $data['action'], 'amount' => $money->amount(),
                'counterparty_account_id' => $data['counterparty_account_id'] ?? null,
                'occurred_on' => $data['occurred_on'], 'note' => $data['note'] ?? null,
            ];
            $hash = $this->idempotency->hash($payload);
            $existing = FinanceFundMovement::query()->ownedBy($user)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    $this->conflict();
                }

                return [$existing, false];
            }

            $delta = $data['action'] === 'top_up' ? $money->amount() : bcsub('0', $money->amount(), 4);
            $current = $this->saved($locked);
            if (bccomp(bcadd($current, $delta, 4), '0', 4) < 0) {
                throw ValidationException::withMessages(['amount' => __('messages.finance_fund_insufficient')]);
            }
            $group = null;
            if ($locked->storage_mode === 'virtual') {
                FinanceAccount::query()->ownedBy($user)->whereKey($locked->account_id)->lockForUpdate()->firstOrFail();
                if (bccomp($delta, '0', 4) > 0) {
                    $reserved = $this->reservedForAccount($user, $locked->account_id);
                    $balance = $this->balances->forAccount($locked->account, true);
                    if (bccomp(bcadd($reserved, $delta, 4), $balance, 4) > 0) {
                        throw ValidationException::withMessages(['amount' => __('messages.finance_fund_capacity')]);
                    }
                }
            } else {
                $counterpartyId = $data['counterparty_account_id'] ?? $locked->funding_account_id;
                if ($counterpartyId === null || (int) $counterpartyId === (int) $locked->account_id) {
                    throw ValidationException::withMessages(['counterparty_account_id' => __('messages.finance_fund_accounts_invalid')]);
                }
                $counterparty = FinanceAccount::query()->ownedBy($user)->findOrFail($counterpartyId);
                if ($counterparty->currency_code !== $locked->currency_code || $counterparty->archived_at !== null) {
                    throw ValidationException::withMessages(['counterparty_account_id' => __('messages.finance_fund_accounts_invalid')]);
                }
                [$group] = $this->ledger->transfer($user, [
                    'source_account_id' => $data['action'] === 'top_up' ? $counterparty->id : $locked->account_id,
                    'destination_account_id' => $data['action'] === 'top_up' ? $locked->account_id : $counterparty->id,
                    'source_amount' => $money->amount(), 'destination_amount' => $money->amount(),
                    'occurred_on' => $data['occurred_on'], 'idempotency_key' => $data['idempotency_key'],
                    'note' => $data['note'] ?? null, 'tag' => null,
                ]);
            }

            $movement = FinanceFundMovement::query()->create([
                'user_id' => $user->id, 'finance_saving_fund_id' => $locked->id,
                'action' => $data['action'], 'delta_amount' => $delta, 'currency_code' => $locked->currency_code,
                'occurred_on' => $data['occurred_on'], 'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash, 'transaction_group_id' => $group?->id, 'note' => $data['note'] ?? null,
            ]);

            return [$movement, true];
        }, 3);
    }

    /** @param array<string, mixed> $data @return array{FinanceFundMovement, bool} */
    private function reverse(User $user, FinanceSavingFund $fund, array $data): array
    {
        $original = FinanceFundMovement::query()->ownedBy($user)->whereKey($data['reverses_movement_id'])
            ->where('finance_saving_fund_id', $fund->id)->with(['reversedBy', 'transactionGroup'])->lockForUpdate()->firstOrFail();
        $payload = ['fund_id' => $fund->id, 'action' => 'reverse', 'reverses_movement_id' => $original->id,
            'note' => $data['note'] ?? null];
        $hash = $this->idempotency->hash($payload);
        $existing = FinanceFundMovement::query()->ownedBy($user)->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                $this->conflict();
            }

            return [$existing, false];
        }
        if ($original->action === 'reverse' || $original->reversedBy !== null) {
            $this->conflict();
        }
        $delta = bcsub('0', (string) $original->delta_amount, 4);
        if (bccomp(bcadd($this->saved($fund), $delta, 4), '0', 4) < 0) {
            throw ValidationException::withMessages(['reverses_movement_id' => __('messages.finance_fund_insufficient')]);
        }
        $group = null;
        if ($original->transactionGroup) {
            [$group] = $this->ledger->reverse($user, $original->transactionGroup, [
                'idempotency_key' => $data['idempotency_key'], 'reason' => trim((string) $data['note']),
            ]);
        }
        $reversal = FinanceFundMovement::query()->create([
            'user_id' => $user->id, 'finance_saving_fund_id' => $fund->id, 'action' => 'reverse',
            'delta_amount' => $delta, 'currency_code' => $fund->currency_code,
            'occurred_on' => now($user->calendarTimezone())->toDateString(), 'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $hash, 'transaction_group_id' => $group?->id,
            'reverses_movement_id' => $original->id, 'note' => $data['note'] ?? null,
        ]);
        if ($fact = FinanceFundOccurrenceFact::query()->ownedBy($user)
            ->where('finance_fund_movement_id', $original->id)->first()) {
            $this->occurrences->syncFromFundFact($fact);
        }

        return [$reversal, true];
    }

    private function saved(FinanceSavingFund $fund): string
    {
        return bcadd('0', (string) ($fund->movements()->sum('delta_amount') ?: '0'), 4);
    }

    private function reservedForAccount(User $user, int $accountId): string
    {
        $value = FinanceFundMovement::query()->where('finance_fund_movements.user_id', $user->id)
            ->join('finance_saving_funds', 'finance_saving_funds.id', '=', 'finance_fund_movements.finance_saving_fund_id')
            ->where('finance_saving_funds.storage_mode', 'virtual')->where('finance_saving_funds.account_id', $accountId)
            ->sum('finance_fund_movements.delta_amount');

        return bcadd('0', (string) ($value ?: '0'), 4);
    }

    private function conflict(): never
    {
        throw new HttpResponseException(response()->json(['message' => __('messages.finance_idempotency_conflict')], 409));
    }
}
