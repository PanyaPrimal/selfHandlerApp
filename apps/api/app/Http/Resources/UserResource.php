<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->resource->ensureProfile();

        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'preferences' => [
                'timezone' => $profile->timezone,
                'locale' => $profile->locale,
                'unit_system' => $profile->unit_system,
                'base_currency' => $profile->base_currency,
                'recommendation_tone' => $profile->recommendation_tone,
                'bmr_formula' => $profile->bmr_formula,
                'calculation_ready' => $profile->calculationReady(),
            ],
        ];
    }
}
