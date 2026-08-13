<?php

namespace Database\Factories;

use App\Models\SupplementCourse;
use App\Models\SupplementIntake;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplementIntake> */
class SupplementIntakeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'supplement_course_id' => fn (array $attributes): int => SupplementCourse::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'supplement_id' => fn (array $attributes): int => SupplementCourse::query()
                ->findOrFail($attributes['supplement_course_id'])->supplement_id,
            'planned_on' => now()->toDateString(),
            'effective_on' => now()->toDateString(),
            'slot' => 'morning',
            'outcome' => SupplementIntake::OUTCOME_TAKEN,
            'dose_quantity' => '1.000000',
            'dose_display_unit' => 'piece',
            'supplement_name' => fn (array $attributes): string => SupplementCourse::query()
                ->findOrFail($attributes['supplement_course_id'])->supplement()->value('name'),
            'taken_at' => now()->utc(),
            'note' => null,
        ];
    }

    public function skipped(): static
    {
        return $this->state(fn (): array => [
            'outcome' => SupplementIntake::OUTCOME_SKIPPED,
            'taken_at' => null,
        ]);
    }
}
