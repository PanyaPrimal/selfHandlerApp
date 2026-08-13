<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceFundMovement;
use App\Models\FinanceLedgerEntry;
use App\Models\FinanceSavingFund;
use App\Models\FinanceTransactionGroup;
use App\Models\User;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceAccountService
{
    public function __construct(
        private readonly FinanceBalanceService $balances,
        private readonly FinanceIdempotency $idempotency,
    ) {}

    /** @return Collection<int, FinanceAccount> */
    public function list(User $user, bool $archived = false): Collection
    {
        $accounts = FinanceAccount::query()->ownedBy($user)
            ->when(! $archived, fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('id')->get();
        $balances = $this->balances->forAccounts($accounts);
        $reserved = FinanceFundMovement::query()
            ->join('finance_saving_funds', 'finance_saving_funds.id', '=', 'finance_fund_movements.finance_saving_fund_id')
            ->where('finance_fund_movements.user_id', $user->id)
            ->where('finance_saving_funds.storage_mode', 'virtual')
            ->whereIn('finance_saving_funds.account_id', $accounts->pluck('id'))
            ->selectRaw('finance_saving_funds.account_id AS account_id, SUM(finance_fund_movements.delta_amount) AS reserved')
            ->groupBy('finance_saving_funds.account_id')->pluck('reserved', 'account_id');

        return $accounts->each(function (FinanceAccount $account) use ($balances, $reserved): void {
            $balance = $balances[$account->id] ?? '0.0000';
            $reserve = bcadd('0', (string) ($reserved[$account->id] ?? '0'), 4);
            $account->setAttribute('balance_projection', $balance);
            $account->setAttribute('reserved_amount_projection', $reserve);
            $account->setAttribute('available_balance_projection', bcsub($balance, $reserve, 4));
            $account->setAttribute('over_reserved_projection', bccomp($reserve, $balance, 4) > 0);
        });
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): FinanceAccount
    {
        return DB::transaction(function () use ($user, $data): FinanceAccount {
            $account = FinanceAccount::query()->create([
                'user_id' => $user->id,
                'name' => trim($data['name']),
                'type' => $data['type'],
                'currency_code' => $data['currency'],
            ]);

            if (array_key_exists('opening_balance', $data)) {
                $amount = Money::of((string) $data['opening_balance'], $account->currency_code);
                if (! $amount->isZero()) {
                    $date = $data['opening_date'] ?? CarbonImmutable::now($user->calendarTimezone())->toDateString();
                    $payload = [
                        'account_id' => $account->id,
                        'amount' => $amount->amount(),
                        'occurred_on' => $date,
                        'note' => $data['opening_note'] ?? null,
                    ];
                    $this->appendAdjustment(
                        $user,
                        $account,
                        $amount,
                        $date,
                        'account-opening:'.$account->id,
                        $payload,
                        $data['opening_note'] ?? null,
                    );
                }
            }

            return $account->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(FinanceAccount $account, User $user, array $data): FinanceAccount
    {
        abort_unless($account->isOwnedBy($user), 404);

        return DB::transaction(function () use ($account, $data): FinanceAccount {
            $locked = FinanceAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $referencedByFund = FinanceSavingFund::query()
                ->where(function ($query) use ($locked): void {
                    $query->where('account_id', $locked->id)->orWhere('funding_account_id', $locked->id);
                })->where('is_archived', false)->exists();
            if (array_key_exists('currency', $data)) {
                if ($data['currency'] !== $locked->currency_code && $referencedByFund) {
                    throw ValidationException::withMessages([
                        'currency' => __('messages.finance_account_fund_reference'),
                    ]);
                }
                if ($data['currency'] !== $locked->currency_code && $locked->entries()->exists()) {
                    throw ValidationException::withMessages([
                        'currency' => __('messages.finance_account_currency_locked'),
                    ]);
                }
                $locked->currency_code = $data['currency'];
            }
            if (array_key_exists('archived', $data)) {
                if ($data['archived'] && ! $locked->archived_at && $referencedByFund) {
                    throw ValidationException::withMessages([
                        'archived' => __('messages.finance_account_fund_reference'),
                    ]);
                }
                if ($data['archived'] && ! $locked->archived_at
                    && bccomp($this->balances->forAccount($locked, true), '0', 4) !== 0) {
                    throw ValidationException::withMessages([
                        'archived' => __('messages.finance_account_archive_balance'),
                    ]);
                }
                $locked->archived_at = $data['archived'] ? now() : null;
            }
            if (array_key_exists('name', $data)) {
                $locked->name = trim($data['name']);
            }
            if (array_key_exists('type', $data)) {
                $locked->type = $data['type'];
            }
            $locked->save();

            return $locked->fresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{account: FinanceAccount, transaction: ?FinanceTransactionGroup}
     */
    public function reconcile(FinanceAccount $account, User $user, array $data): array
    {
        abort_unless($account->isOwnedBy($user), 404);

        return DB::transaction(function () use ($account, $user, $data): array {
            $locked = FinanceAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages([
                    'account' => __('messages.finance_account_archived'),
                ]);
            }

            $observed = Money::of((string) $data['observed_balance'], $locked->currency_code);
            $payload = [
                'account_id' => $locked->id,
                'observed_balance' => $observed->amount(),
                'occurred_on' => $data['occurred_on'],
                'reason' => trim($data['reason']),
            ];
            $existing = $this->idempotency->existing($user, $data['idempotency_key'], $payload);
            if ($existing) {
                return ['account' => $locked, 'transaction' => $existing];
            }

            $current = Money::of($this->balances->forAccount($locked, true), $locked->currency_code);
            $delta = $observed->add($current->negate());
            $group = $delta->isZero() ? null : $this->appendAdjustment(
                $user,
                $locked,
                $delta,
                $data['occurred_on'],
                $data['idempotency_key'],
                $payload,
                trim($data['reason']),
            );

            return ['account' => $locked, 'transaction' => $group];
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function appendAdjustment(
        User $user,
        FinanceAccount $account,
        Money $delta,
        string $occurredOn,
        string $idempotencyKey,
        array $payload,
        ?string $note,
    ): FinanceTransactionGroup {
        $group = FinanceTransactionGroup::query()->create([
            'user_id' => $user->id,
            'public_id' => (string) Str::uuid(),
            'kind' => 'adjustment',
            'occurred_on' => $occurredOn,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $this->idempotency->hash($payload),
            'note' => $note,
        ]);
        FinanceLedgerEntry::query()->create([
            'user_id' => $user->id,
            'transaction_group_id' => $group->id,
            'account_id' => $account->id,
            'category_id' => null,
            'role' => 'primary',
            'delta_amount' => $delta->amount(),
            'currency_code' => $account->currency_code,
        ]);

        return $group->load(['entries.account', 'entries.category', 'reverses', 'reversedBy']);
    }
}
