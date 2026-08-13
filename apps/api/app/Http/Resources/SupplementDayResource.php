<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplementDayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->resource['date'],
            'today' => $this->resource['today'],
            'occurrences' => SupplementOccurrenceResource::collection(
                $this->resource['occurrences'],
            )->resolve($request),
            'summary' => $this->resource['summary'],
        ];
    }
}
