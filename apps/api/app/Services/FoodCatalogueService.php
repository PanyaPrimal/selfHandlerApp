<?php

namespace App\Services;

use App\Models\FoodItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FoodCatalogueService
{
    public function list(User $user, string $state = 'active'): Collection
    {
        return FoodItem::query()
            ->where(function ($query) use ($user, $state): void {
                if ($state !== 'archived') {
                    $query->whereNull('user_id');
                }
                $query->orWhere('user_id', $user->id);
            })
            ->when($state === 'active', fn ($query) => $query->where('is_archived', false))
            ->when($state === 'archived', fn ($query) => $query->where('user_id', $user->id)->where('is_archived', true))
            ->orderByRaw('CASE WHEN system_key IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')->orderBy('id')->get();
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $user, array $attributes): FoodItem
    {
        $data = $this->validate($user, $attributes);

        return FoodItem::create(['user_id' => $user->id, ...$data]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(FoodItem $food, User $user, array $attributes): FoodItem
    {
        $food = FoodItem::query()->ownedBy($user)->findOrFail($food->id);
        $merged = [...$food->only([
            'name', 'basis_unit', 'is_beverage', 'calories_per_100', 'protein_per_100',
            'fat_per_100', 'carbs_per_100', 'quality_score', 'hydration_ratio',
        ]), ...$attributes];
        $data = $this->validate($user, $merged, $food);
        $archived = array_key_exists('is_archived', $attributes) ? (bool) $attributes['is_archived'] : null;
        $food->fill($data);
        if ($archived !== null) {
            $food->applyLifecycle($archived);
        }
        $food->save();

        return $food->fresh();
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function validate(User $user, array $attributes, ?FoodItem $food = null): array
    {
        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:160', Rule::unique('food_items', 'name')
                ->where('user_id', $user->id)->ignore($food?->id)],
            'basis_unit' => ['required', Rule::in([FoodItem::BASIS_GRAM, FoodItem::BASIS_MILLILITRE])],
            'is_beverage' => ['required', 'boolean'],
            'calories_per_100' => ['required', 'numeric', 'between:0,1000000'],
            'protein_per_100' => ['required', 'numeric', 'between:0,1000000'],
            'fat_per_100' => ['required', 'numeric', 'between:0,1000000'],
            'carbs_per_100' => ['required', 'numeric', 'between:0,1000000'],
            'quality_score' => ['present', 'nullable', 'numeric', 'between:0,100'],
            'hydration_ratio' => ['required', 'numeric', 'between:0,1'],
        ]);
        $validator->after(function ($validator) use ($attributes): void {
            $beverage = (bool) ($attributes['is_beverage'] ?? false);
            $basis = $attributes['basis_unit'] ?? null;
            $hydration = (float) ($attributes['hydration_ratio'] ?? 0);
            $quality = $attributes['quality_score'] ?? null;
            if (($beverage && $basis !== FoodItem::BASIS_MILLILITRE)
                || (! $beverage && $basis !== FoodItem::BASIS_GRAM)) {
                $validator->errors()->add('basis_unit', __('messages.nutrition_basis_mismatch'));
            }
            if (! $beverage && $hydration !== 0.0) {
                $validator->errors()->add('hydration_ratio', __('messages.nutrition_solid_hydration'));
            }
            if ($beverage && $quality !== null) {
                $validator->errors()->add('quality_score', __('messages.nutrition_beverage_quality'));
            }
        });

        return $validator->validate();
    }
}
