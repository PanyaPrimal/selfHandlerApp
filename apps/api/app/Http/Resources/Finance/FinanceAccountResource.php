<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'currency' => $this->currency_code,
            'balance' => $this->getAttribute('balance_projection') ?? '0.0000',
            'reserved_amount' => $this->getAttribute('reserved_amount_projection') ?? '0.0000',
            'available_balance' => $this->getAttribute('available_balance_projection')
                ?? ($this->getAttribute('balance_projection') ?? '0.0000'),
            'over_reserved' => $this->getAttribute('over_reserved_projection') ?? false,
            'archived' => $this->archived_at !== null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
