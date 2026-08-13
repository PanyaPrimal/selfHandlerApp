<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BodyMeasurementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('attachments');

        return [
            'id' => $this->id,
            'metric' => $this->metric,
            'measured_on' => $this->measured_on->toDateString(),
            'value' => $this->value,
            'note' => $this->note,
            'attachments' => AttachmentResource::collection($this->attachments)->resolve($request),
        ];
    }
}
