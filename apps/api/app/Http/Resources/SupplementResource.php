<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $stock = $this->getAttribute('stock_projection');
        $forecast = $this->getAttribute('forecast_projection');
        $proposal = $this->getAttribute('proposal_projection');
        unset($stock['has_facts']);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'form' => $this->form,
            'stock_unit' => $this->stock_unit,
            'preferred_display_unit' => $this->preferred_display_unit,
            'usual_dose_quantity' => $this->usual_dose_quantity,
            'package_quantity' => $this->package_quantity,
            'restock_lead_days' => $this->restock_lead_days,
            'note' => $this->note,
            'is_archived' => $this->is_archived,
            'archived_at' => $this->archived_at?->toISOString(),
            'stock' => $stock,
            'forecast' => $forecast,
            'restock_proposal' => $proposal
                ? SupplementRestockProposalResource::make($proposal)->resolve($request)
                : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
