<?php

namespace App\Services;

use App\Models\Supplement;
use App\Models\User;
use App\ValueObjects\SupplementQuantity;
use Illuminate\Validation\ValidationException;

class SupplementService
{
    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Supplement
    {
        $data = $this->quantities($data, $data['stock_unit'], $data['preferred_display_unit']);

        return Supplement::create([
            ...$data,
            'user_id' => $user->id,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(Supplement $supplement, User $user, array $data): Supplement
    {
        abort_unless($supplement->isOwnedBy($user), 404);
        if (array_key_exists('stock_unit', $data) && $data['stock_unit'] !== $supplement->stock_unit
            && ($supplement->courses()->exists() || $supplement->intakes()->exists()
                || $supplement->stockMovements()->exists())) {
            throw ValidationException::withMessages([
                'stock_unit' => __('messages.supplement_stock_unit_locked'),
            ]);
        }

        $stockUnit = $data['stock_unit'] ?? $supplement->stock_unit;
        $displayUnit = $data['preferred_display_unit'] ?? $supplement->preferred_display_unit;
        $data = $this->quantities($data, $stockUnit, $displayUnit);
        $supplement->applyLifecycle($data);
        $supplement->save();

        return $supplement->refresh();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function quantities(array $data, string $stockUnit, string $displayUnit): array
    {
        if (array_key_exists('usual_dose_quantity', $data)) {
            $data['usual_dose_quantity'] = SupplementQuantity::fromDisplay(
                $data['usual_dose_quantity'], $displayUnit, $stockUnit,
            )->canonical();
        }
        if (array_key_exists('package_quantity', $data) && $data['package_quantity'] !== null) {
            $data['package_quantity'] = SupplementQuantity::fromDisplay(
                $data['package_quantity'], $displayUnit, $stockUnit,
            )->canonical();
        }

        return $data;
    }
}
