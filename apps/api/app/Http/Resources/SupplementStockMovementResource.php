<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplementStockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplement_id' => $this->supplement_id,
            'kind' => $this->kind,
            'quantity_delta' => $this->quantity_delta,
            'stock_unit' => $this->supplement->stock_unit,
            'effective_on' => $this->effective_on->format('Y-m-d'),
            'reason' => $this->reason,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
