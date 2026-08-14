<?php

namespace Tests\Feature\Portability;

use App\Models\BodyMeasurement;
use App\Models\DailyReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortabilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_are_authenticated(): void
    {
        $this->get('/api/portability/backup')->assertUnauthorized();
        $this->postJson('/api/portability/restore/validate')->assertUnauthorized();
        $this->postJson('/api/portability/restore')->assertUnauthorized();
    }

    public function test_backup_is_private_versioned_and_owner_only(): void
    {
        $owner = User::factory()->create(['name' => 'Portable Owner']);
        $foreign = User::factory()->create(['name' => 'Foreign Owner']);
        DailyReview::query()->create(['user_id' => $owner->id, 'review_date' => '2026-08-01', 'notes' => 'owned note']);
        DailyReview::query()->create(['user_id' => $foreign->id, 'review_date' => '2026-08-01', 'notes' => 'foreign secret']);

        $response = $this->actingAs($owner)->get('/api/portability/backup')->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $records = $zip->getFromName('data/records.json');
        $profile = $zip->getFromName('data/profile.json');
        $zip->close();
        @unlink($path);

        $this->assertSame('selfhandler-backup', $manifest['format']);
        $this->assertSame(1, $manifest['schema_version']);
        $this->assertStringContainsString('owned note', $records);
        $this->assertStringNotContainsString('foreign secret', $records);
        $this->assertStringContainsString('Portable Owner', $profile);
        foreach (['email', 'password', 'remember_token', 'user_id', 'disk', 'path'] as $secret) {
            $this->assertStringNotContainsString('"'.$secret.'"', $profile.$records);
        }
    }

    public function test_validation_is_read_only_and_restore_round_trips_into_empty_target(): void
    {
        $source = User::factory()->create(['name' => 'Source Name']);
        $source->ensureProfile()->update([
            'locale' => 'uk-UA', 'timezone' => 'Europe/Kyiv', 'unit_system' => 'metric',
            'base_currency' => 'EUR', 'date_of_birth' => '1990-05-17', 'sex' => 'female',
            'height_meters' => '1.720', 'weight_grams' => 65000, 'body_fat_percentage' => '24.50',
            'baseline_activity' => 'moderate', 'recommendation_tone' => 'direct', 'bmr_formula' => 'mifflin_st_jeor',
            'theme_preferences' => [
                'scheme' => 'dark', 'accent' => 'brick', 'accent_hex' => '#9a493d',
                'background' => 'mist', 'background_hex' => '#20252b', 'texture' => false,
                'mono_numerals' => true, 'motion' => 'reduce',
            ],
        ]);
        DB::table('notification_settings')->insert([
            'user_id' => $source->id, 'quiet_hours_enabled' => true,
            'quiet_starts_at' => '22:30', 'quiet_ends_at' => '07:15',
            'digest_enabled' => true, 'digest_time' => '08:45',
            'categories' => json_encode(['routine' => true, 'finance' => false], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DailyReview::query()->create(['user_id' => $source->id, 'review_date' => '2026-08-01', 'energy' => 8]);
        BodyMeasurement::query()->create(['user_id' => $source->id, 'metric' => 'body_mass', 'measured_on' => '2026-08-01', 'value' => '80000']);
        $response = $this->actingAs($source)->get('/api/portability/backup')->assertOk();
        $backup = file_get_contents($response->baseResponse->getFile()->getPathname());
        @unlink($response->baseResponse->getFile()->getPathname());

        $target = User::factory()->create(['name' => 'Target Name', 'email' => 'target@example.test']);
        $upload = fn () => UploadedFile::fake()->createWithContent('selfhandler.zip', $backup);
        $validation = $this->actingAs($target)->post('/api/portability/restore/validate', ['backup' => $upload()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.eligible', true);
        $this->assertDatabaseMissing('daily_reviews', ['user_id' => $target->id]);

        $this->actingAs($target)->post('/api/portability/restore', [
            'backup' => $upload(), 'restore_token' => $validation->json('data.restore_token'), 'confirmation' => 'RESTORE',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.total_records', 2);

        $target->refresh();
        $this->assertSame('Source Name', $target->name);
        $this->assertSame('target@example.test', $target->email);
        $this->assertDatabaseHas('daily_reviews', ['user_id' => $target->id, 'review_date' => '2026-08-01', 'energy' => 8]);
        $this->assertDatabaseHas('body_measurements', ['user_id' => $target->id, 'measured_on' => '2026-08-01', 'value' => 80000]);
        $this->assertSame('uk-UA', $target->ensureProfile()->locale);
        $this->assertSame('EUR', $target->ensureProfile()->base_currency);
        $this->assertSame('1990-05-17', $target->ensureProfile()->date_of_birth->format('Y-m-d'));
        $this->assertSame('dark', $target->ensureProfile()->theme_preferences['scheme']);
        $settings = DB::table('notification_settings')->where('user_id', $target->id)->first();
        $this->assertTrue((bool) $settings->quiet_hours_enabled);
        $this->assertSame('22:30', substr((string) $settings->quiet_starts_at, 0, 5));
        $this->assertSame(['routine' => true, 'finance' => false], json_decode($settings->categories, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_restore_rejects_non_empty_target_and_does_not_overwrite(): void
    {
        $source = User::factory()->create();
        DailyReview::query()->create(['user_id' => $source->id, 'review_date' => '2026-08-01', 'energy' => 8]);
        $response = $this->actingAs($source)->get('/api/portability/backup')->assertOk();
        $backup = file_get_contents($response->baseResponse->getFile()->getPathname());
        @unlink($response->baseResponse->getFile()->getPathname());
        $target = User::factory()->create();
        DailyReview::query()->create(['user_id' => $target->id, 'review_date' => '2026-08-02', 'energy' => 2]);

        $this->actingAs($target)->post('/api/portability/restore/validate', [
            'backup' => UploadedFile::fake()->createWithContent('selfhandler.zip', $backup),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.restore_token', null);
        $this->assertDatabaseCount('daily_reviews', 2);
    }

    public function test_corrupt_archive_fails_without_writes(): void
    {
        $target = User::factory()->create();
        $this->actingAs($target)->post('/api/portability/restore/validate', [
            'backup' => UploadedFile::fake()->createWithContent('malicious.zip', "not a zip\0../escape"),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseMissing('daily_reviews', ['user_id' => $target->id]);
    }
}
