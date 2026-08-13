<?php

namespace Database\Factories;

use App\Models\Supplement;
use App\Models\SupplementRestockProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplementRestockProposal> */
class SupplementRestockProposalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'supplement_id' => fn (array $attributes): int => Supplement::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'active_supplement_id' => fn (array $attributes): int => $attributes['supplement_id'],
            'shortage_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'forecast_runout_on' => now()->addDays(5)->toDateString(),
            'needed_by' => now()->subDays(2)->addDays(5)->toDateString(),
            'suggested_quantity' => '30.000000',
            'stock_unit' => 'piece',
            'status' => SupplementRestockProposal::STATUS_OPEN,
            'dismissed_at' => null,
            'resolved_at' => null,
        ];
    }
}
