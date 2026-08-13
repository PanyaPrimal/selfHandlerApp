<?php

namespace Database\Factories;

use App\Models\FinanceTransactionGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FinanceTransactionGroup> */
class FinanceTransactionGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'public_id' => (string) Str::uuid(),
            'kind' => 'adjustment',
            'occurred_on' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', (string) Str::uuid()),
            'note' => null,
            'tag' => null,
            'reverses_group_id' => null,
            'reversal_reason' => null,
            'fx_from_currency' => null,
            'fx_to_currency' => null,
            'effective_rate' => null,
        ];
    }
}
