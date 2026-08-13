<?php

namespace Database\Factories;

use App\Models\Supplement;
use App\Models\SupplementStockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplementStockMovement> */
class SupplementStockMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'supplement_id' => fn (array $attributes): int => Supplement::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'kind' => SupplementStockMovement::KIND_RESTOCK,
            'quantity_delta' => '30.000000',
            'effective_on' => now()->toDateString(),
            'reason' => null,
            'note' => null,
        ];
    }
}
