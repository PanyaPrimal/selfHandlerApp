<?php

namespace Tests\Support;

use App\Models\BodyMeasurement;
use App\Models\Meal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class AttachmentTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'attachments.disk' => 'local',
            'attachments.max_source_bytes' => 5 * 1024 * 1024,
            'attachments.max_stored_bytes' => 5 * 1024 * 1024,
            'attachments.max_dimension' => 2560,
            'attachments.max_source_pixels' => 40_000_000,
            'attachments.max_per_parent' => 10,
            'attachments.max_bytes_per_user' => 100 * 1024 * 1024,
        ]);
        Storage::fake('local');
    }

    protected function user(string $email = 'owner@example.test'): User
    {
        return User::factory()->create(['email' => $email, 'email_verified_at' => null]);
    }

    protected function measurement(User $user, array $attributes = []): BodyMeasurement
    {
        return BodyMeasurement::query()->create([
            'user_id' => $user->id,
            'metric' => 'body_mass',
            'measured_on' => '2026-08-13',
            'value' => '70000.0000',
            'note' => 'Baseline',
            ...$attributes,
        ]);
    }

    protected function meal(User $user, array $attributes = []): Meal
    {
        return Meal::query()->create([
            'user_id' => $user->id,
            'consumed_on' => '2026-08-13',
            'name' => 'Lunch',
            'category' => 'lunch',
            'consumed_at_local' => '12:30',
            'note' => null,
            'submission_key' => $attributes['submission_key'] ?? fake()->uuid(),
            ...$attributes,
        ]);
    }

    protected function image(string $name = 'progress.png', int $width = 120, int $height = 80): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'attachment-fixture-');
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 42, 92, 130);
        imagefill($image, 0, 0, $color);
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    protected function uploadPath(string $type, int $id, string $key): string
    {
        return '/api/attachments?'.http_build_query([
            'attachable_type' => $type,
            'attachable_id' => $id,
            'upload_key' => $key,
        ]);
    }
}
