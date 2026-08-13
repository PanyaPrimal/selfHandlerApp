<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\BodyMeasurement;
use App\Models\Meal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @extends Factory<Attachment> */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'attachable_type' => 'body_measurement',
            'attachable_id' => fn (array $attributes) => BodyMeasurement::query()->create([
                'user_id' => $attributes['user_id'], 'metric' => 'body_mass',
                'measured_on' => now()->toDateString(), 'value' => '70000.0000', 'note' => null,
            ])->id,
            'disk' => config('attachments.disk', 'local'),
            'path' => fn (array $attributes) => 'attachments/'.$attributes['user_id'].'/'.Str::uuid().'.png',
            'original_name' => 'photo.png', 'mime_type' => 'image/png', 'size_bytes' => 100,
            'kind' => Attachment::KIND_PHOTO, 'width' => 10, 'height' => 10,
            'sha256' => hash('sha256', fake()->uuid()), 'upload_key' => fake()->uuid(), 'meta' => null,
        ];
    }

    public function forBodyMeasurement(BodyMeasurement $measurement): static
    {
        return $this->forParent($measurement);
    }

    public function forMeal(Meal $meal): static
    {
        return $this->forParent($meal);
    }

    private function forParent(Model $parent): static
    {
        return $this->state(fn (): array => [
            'user_id' => $parent->user_id,
            'attachable_type' => Attachment::aliasFor($parent),
            'attachable_id' => $parent->id,
            'path' => 'attachments/'.$parent->user_id.'/'.Str::uuid().'.png',
        ]);
    }
}
