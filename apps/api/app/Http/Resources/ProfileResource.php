<?php

namespace App\Http\Resources;

use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var UserProfile $profile */
        $profile = $this->resource;

        return [
            'user' => (new UserResource($profile->user))->resolve($request),
            'timezone' => $profile->timezone,
            'locale' => $profile->locale,
            'unit_system' => $profile->unit_system,
            'base_currency' => $profile->base_currency,
            'recommendation_tone' => $profile->recommendation_tone,
            'bmr_formula' => $profile->bmr_formula,
            'date_of_birth' => $profile->date_of_birth?->toDateString(),
            'sex' => $profile->sex,
            'height_meters' => $profile->height_meters === null ? null : (float) $profile->height_meters,
            'weight_grams' => $profile->weight_grams,
            'body_fat_percentage' => $profile->body_fat_percentage === null ? null : (float) $profile->body_fat_percentage,
            'baseline_activity' => $profile->baseline_activity,
            'theme' => $profile->themePreferences(),
            'calculation_ready' => $profile->calculationReady(),
            'missing_fields' => $profile->missingCalculationFields(),
            'updated_at' => $profile->updated_at?->toISOString(),
        ];
    }
}
