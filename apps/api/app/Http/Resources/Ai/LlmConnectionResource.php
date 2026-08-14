<?php

namespace App\Http\Resources\Ai;

use App\Models\LlmConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LlmConnection */
class LlmConnectionResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'model' => $this->model,
            'key_mask' => $this->keyMask(),
            'parameters' => LlmConnection::normalizeParameters($this->parameters),
            'status' => $this->status,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_error_code' => $this->last_error_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
