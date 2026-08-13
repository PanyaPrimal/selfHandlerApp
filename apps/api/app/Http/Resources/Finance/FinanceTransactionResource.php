<?php

namespace App\Http\Resources\Finance;

use App\Models\FinanceTransactionGroup;
use App\Models\Item;
use App\Models\SupplementRestockProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->loadMissing(['entries.account', 'entries.category', 'reverses', 'reversedBy']);
        if ($this->source_type === FinanceTransactionGroup::SOURCE_PURCHASE_ITEM) {
            $this->loadMissing('sourcePurchaseItem');
        } elseif ($this->source_type === FinanceTransactionGroup::SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL) {
            $this->loadMissing('sourceRestockProposal.supplement');
        }

        return [
            'id' => $this->public_id,
            'kind' => $this->kind,
            'occurred_on' => $this->occurred_on->format('Y-m-d'),
            'note' => $this->note,
            'tag' => $this->tag,
            'source' => $this->sourceContext(),
            'reverses_id' => $this->reverses?->public_id,
            'reversed_by_id' => $this->reversedBy?->public_id,
            'reversal_reason' => $this->reversal_reason,
            'transfer' => $this->effective_rate === null ? null : [
                'from_currency' => $this->fx_from_currency,
                'to_currency' => $this->fx_to_currency,
                'effective_rate' => $this->effective_rate,
            ],
            'entries' => $this->entries->map(fn ($entry): array => [
                'id' => $entry->id,
                'account_id' => $entry->account_id,
                'account_name' => $entry->account->name,
                'category_id' => $entry->category_id,
                'category_label' => $entry->category?->displayLabel(),
                'role' => $entry->role,
                'delta_amount' => $entry->delta_amount,
                'currency' => $entry->currency_code,
            ])->values()->all(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function sourceContext(): ?array
    {
        if ($this->source_type === null || $this->source_id === null) {
            return null;
        }
        if ($this->source_type === FinanceTransactionGroup::SOURCE_PURCHASE_ITEM) {
            $source = $this->sourcePurchaseItem;

            return ['type' => $this->source_type, 'id' => $this->source_id,
                'label' => $source?->title ?? '#'.$this->source_id,
                'action_url' => '/storage?item='.$this->source_id,
                'active' => $source?->status === Item::STATUS_ACTIVE];
        }
        $source = $this->sourceRestockProposal;

        return ['type' => $this->source_type, 'id' => $this->source_id,
            'label' => $source?->supplement?->name ?? '#'.$this->source_id,
            'action_url' => '/supplements?restock='.$this->source_id,
            'active' => $source?->status === SupplementRestockProposal::STATUS_OPEN];
    }
}
