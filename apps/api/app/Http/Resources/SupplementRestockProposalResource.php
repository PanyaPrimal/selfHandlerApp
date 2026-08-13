<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplementRestockProposalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplement_id' => $this->supplement_id,
            'forecast_runout_on' => $this->forecast_runout_on->format('Y-m-d'),
            'needed_by' => $this->needed_by->format('Y-m-d'),
            'suggested_quantity' => $this->suggested_quantity,
            'stock_unit' => $this->stock_unit,
            'status' => $this->status,
            'dismissed_at' => $this->dismissed_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
