<?php

namespace App\Services;

use App\Models\Supplement;
use App\Models\SupplementStockMovement;
use App\Models\User;
use App\ValueObjects\SupplementQuantity;

class SupplementStockMovementService
{
    /** @param array<string, mixed> $data */
    public function create(Supplement $supplement, User $user, array $data): SupplementStockMovement
    {
        abort_unless($supplement->isOwnedBy($user), 404);
        $quantity = SupplementQuantity::fromDisplay(
            $data['quantity'], $data['display_unit'], $supplement->stock_unit,
        )->canonical();

        return SupplementStockMovement::create([
            'user_id' => $user->id,
            'supplement_id' => $supplement->id,
            'kind' => $data['kind'],
            'quantity_delta' => $quantity,
            'effective_on' => $data['effective_on'],
            'reason' => $data['kind'] === SupplementStockMovement::KIND_CORRECTION ? $data['reason'] : null,
            'note' => $data['note'],
        ]);
    }
}
