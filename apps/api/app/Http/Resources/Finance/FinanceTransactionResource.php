<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->loadMissing(['entries.account', 'entries.category', 'reverses', 'reversedBy']);

        return [
            'id' => $this->public_id,
            'kind' => $this->kind,
            'occurred_on' => $this->occurred_on->format('Y-m-d'),
            'note' => $this->note,
            'tag' => $this->tag,
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
}
