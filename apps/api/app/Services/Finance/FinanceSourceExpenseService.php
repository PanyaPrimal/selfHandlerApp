<?php

namespace App\Services\Finance;

use App\Models\FinanceDebt;
use App\Models\FinanceTransactionGroup;
use App\Models\Item;
use App\Models\SupplementRestockProposal;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceSourceExpenseService
{
    public function __construct(private readonly FinanceLedgerService $ledger) {}

    /** @param array<string, mixed> $data @return array{FinanceTransactionGroup, bool, array<string, mixed>} */
    public function post(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $retry = FinanceTransactionGroup::query()->ownedBy($user)
                ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($retry) {
                $source = $this->source($user, $data['source_type'], (int) $data['source_id'], true, true);
                [$group] = $this->ledger->postActual($user, [
                    'kind' => 'expense', 'account_id' => $data['account_id'], 'category_id' => $data['category_id'],
                    'amount' => $data['amount'], 'occurred_on' => $data['occurred_on'],
                    'idempotency_key' => $data['idempotency_key'], 'note' => $data['note'], 'tag' => null,
                    'source_type' => $data['source_type'], 'source_id' => $source->id,
                ]);

                return [$group, false, $this->context($data['source_type'], $source)];
            }
            $source = $this->source($user, $data['source_type'], (int) $data['source_id'], true);
            $active = FinanceTransactionGroup::query()->ownedBy($user)
                ->where('source_type', $data['source_type'])->where('source_id', $source->id)
                ->whereNull('reverses_group_id')->whereDoesntHave('reversedBy')->lockForUpdate()->first();
            if ($active) {
                if ($active->idempotency_key !== $data['idempotency_key']) {
                    $this->conflict();
                }

                return [$active, false, $this->context($data['source_type'], $source)];
            }
            if ($data['source_type'] === FinanceTransactionGroup::SOURCE_PURCHASE_ITEM
                && FinanceDebt::query()->ownedBy($user)->where('purchase_item_id', $source->id)->exists()) {
                throw ValidationException::withMessages(['source_id' => __('messages.finance_purchase_has_debt')]);
            }

            [$group, $created] = $this->ledger->postActual($user, [
                'kind' => 'expense', 'account_id' => $data['account_id'], 'category_id' => $data['category_id'],
                'amount' => $data['amount'], 'occurred_on' => $data['occurred_on'],
                'idempotency_key' => $data['idempotency_key'], 'note' => $data['note'], 'tag' => null,
                'source_type' => $data['source_type'], 'source_id' => $source->id,
            ]);
            if ($source instanceof Item) {
                $source->applyStatus(Item::STATUS_DONE);
                $source->save();
            }

            return [$group, $created, $this->context($data['source_type'], $source)];
        }, 3);
    }

    public function synchronizePurchaseAfterReversal(User $user, FinanceTransactionGroup $original): void
    {
        if ($original->source_type !== FinanceTransactionGroup::SOURCE_PURCHASE_ITEM) {
            return;
        }
        $item = Item::query()->ownedBy($user)->whereKey($original->source_id)->lockForUpdate()->first();
        if (! $item) {
            return;
        }
        $hasActiveExpense = FinanceTransactionGroup::query()->ownedBy($user)
            ->where('source_type', FinanceTransactionGroup::SOURCE_PURCHASE_ITEM)->where('source_id', $item->id)
            ->whereNull('reverses_group_id')->whereDoesntHave('reversedBy')->exists();
        $hasDebt = FinanceDebt::query()->ownedBy($user)->where('purchase_item_id', $item->id)->exists();
        if (! $hasActiveExpense && ! $hasDebt && $item->status === Item::STATUS_DONE) {
            $item->applyStatus(Item::STATUS_ACTIVE);
            $item->save();
        }
    }

    private function source(User $user, string $type, int $id, bool $lock, bool $allowClosed = false): Item|SupplementRestockProposal
    {
        $query = match ($type) {
            FinanceTransactionGroup::SOURCE_PURCHASE_ITEM => Item::query()->ownedBy($user),
            FinanceTransactionGroup::SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL => SupplementRestockProposal::query()->ownedBy($user),
            default => throw ValidationException::withMessages(['source_type' => __('messages.finance_source_invalid')]),
        };
        if ($lock) {
            $query->lockForUpdate();
        }
        $source = $query->findOrFail($id);
        $valid = $source instanceof Item
            ? $source->type === Item::TYPE_PURCHASE && $source->status === Item::STATUS_ACTIVE
            : $source->status === SupplementRestockProposal::STATUS_OPEN;
        if (! $valid && ! $allowClosed) {
            throw ValidationException::withMessages(['source_id' => __('messages.finance_source_inactive')]);
        }

        return $source;
    }

    /** @return array<string, mixed> */
    private function context(string $type, Item|SupplementRestockProposal $source): array
    {
        return $source instanceof Item ? [
            'type' => $type, 'id' => $source->id, 'label' => $source->title,
            'action_url' => '/storage?item='.$source->id, 'active' => $source->status === Item::STATUS_ACTIVE,
        ] : [
            'type' => $type, 'id' => $source->id, 'label' => $source->supplement()->value('name') ?? '',
            'action_url' => '/supplements?restock='.$source->id,
            'active' => $source->status === SupplementRestockProposal::STATUS_OPEN,
        ];
    }

    private function conflict(): never
    {
        throw new HttpResponseException(response()->json(['message' => __('messages.finance_source_conflict')], 409));
    }
}
