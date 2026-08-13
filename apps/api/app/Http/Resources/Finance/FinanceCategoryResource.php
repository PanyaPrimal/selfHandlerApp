<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'parent_id' => $this->parent_id,
            'builtin_key' => $this->builtin_key,
            'name' => $this->name,
            'label' => $this->resource->displayLabel(),
            'archived' => $this->archived_at !== null,
            'used' => (bool) ($this->entries_exists ?? $this->entries_count ?? false),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
