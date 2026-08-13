<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\NutritionSettings;
use App\Models\User;
use App\ValueObjects\BodyMetric;
use Illuminate\Support\Facades\Validator;

class NutritionSettingsService
{
    public function get(User $user): NutritionSettings
    {
        return NutritionSettings::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['protein_percent' => 20, 'fat_percent' => 30, 'carbs_percent' => 50],
        );
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $user, array $attributes): NutritionSettings
    {
        $validator = Validator::make($attributes, [
            'body_goal_id' => ['present', 'nullable', 'integer'],
            'protein_percent' => ['required', 'numeric', 'between:10,35'],
            'fat_percent' => ['required', 'numeric', 'between:20,35'],
            'carbs_percent' => ['required', 'numeric', 'between:45,65'],
            'water_override_ml' => ['present', 'nullable', 'integer', 'between:1000,6000'],
        ]);
        $validator->after(function ($validator) use ($attributes, $user): void {
            $sum = (float) ($attributes['protein_percent'] ?? 0)
                + (float) ($attributes['fat_percent'] ?? 0)
                + (float) ($attributes['carbs_percent'] ?? 0);
            if (abs($sum - 100) > 0.0001) {
                $validator->errors()->add('carbs_percent', __('messages.nutrition_macro_sum'));
            }
            if (($attributes['body_goal_id'] ?? null) !== null) {
                $goal = Goal::query()->ownedBy($user)->whereKey($attributes['body_goal_id'])
                    ->where('type', Goal::TYPE_BODY)->where('status', 'active')->where('is_archived', false)
                    ->with('bodyDetail')->first();
                if (! $goal || $goal->bodyDetail?->metric !== BodyMetric::BodyMass) {
                    $validator->errors()->add('body_goal_id', __('messages.nutrition_goal_unavailable'));
                }
            }
        });
        $data = $validator->validate();
        $settings = $this->get($user);
        $settings->update($data);

        return $settings->fresh();
    }
}
