<?php

namespace Tests\Feature\Portability;

use App\Exceptions\Attachments\AttachmentStorageException;
use App\Models\Attachment;
use App\Models\DailyReview;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\FileStorage;
use App\Services\Portability\PortableBackupReader;
use App\Services\Portability\PortableBackupRestorer;
use App\Services\Portability\RestoreEligibilityService;
use App\Services\Portability\RestoreTokenService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\AttachmentTestCase;
use ZipArchive;

class PortabilitySecurityTest extends AttachmentTestCase
{
    public function test_body_and_meal_attachments_round_trip_as_manifest_members_with_new_private_paths(): void
    {
        $source = $this->user('source@example.test');
        $measurement = $this->measurement($source);
        $meal = $this->meal($source);
        $service = app(AttachmentService::class);
        $bodyAttachment = $service->upload($source, 'body_measurement', $measurement->id, 'body-source', $this->image())->attachment;
        $mealAttachment = $service->upload($source, 'meal', $meal->id, 'meal-source', $this->image('meal.png', 90, 90))->attachment;
        $foreign = $this->user('foreign@example.test');
        $service->upload($foreign, 'body_measurement', $this->measurement($foreign)->id, 'foreign-source', $this->image('foreign.png'));
        $sourcePaths = [$bodyAttachment->path, $mealAttachment->path];
        $backup = $this->backupBytes($source);

        $target = $this->user('target@example.test');
        $validation = $this->validateBytes($target, $backup)->assertOk()->assertJsonPath('data.counts.attachments', 2);
        $this->restoreBytes($target, $backup, $validation->json('data.restore_token'))->assertOk()
            ->assertJsonPath('data.attachments', 2);

        $restored = Attachment::query()->where('user_id', $target->id)->orderBy('id')->get();
        $this->assertCount(2, $restored);
        $this->assertSame([$bodyAttachment->sha256, $mealAttachment->sha256], $restored->pluck('sha256')->all());
        foreach ($restored as $attachment) {
            $this->assertNotContains($attachment->path, $sourcePaths);
            Storage::disk('local')->assertExists($attachment->path);
            $this->assertSame($attachment->sha256, hash('sha256', Storage::disk('local')->get($attachment->path)));
        }
        $this->assertDatabaseHas('body_measurements', ['user_id' => $target->id, 'metric' => 'body_mass']);
        $this->assertDatabaseHas('meals', ['user_id' => $target->id, 'name' => 'Lunch']);
    }

    public function test_export_fails_closed_when_attachment_bytes_are_missing(): void
    {
        $source = $this->user('source@example.test');
        $attachment = app(AttachmentService::class)->upload(
            $source, 'body_measurement', $this->measurement($source)->id, 'missing-source', $this->image(),
        )->attachment;
        Storage::disk('local')->delete($attachment->path);

        $this->actingAs($source)->get('/api/portability/backup')->assertUnprocessable()
            ->assertJsonPath('errors.backup.0', 'This is not an intact supported SelfHandler backup. [attachment_content_mismatch]');
    }

    public function test_restore_token_is_bound_to_target_and_archive_and_tampering_fails_without_writes(): void
    {
        $source = $this->user('source@example.test');
        DailyReview::query()->create(['user_id' => $source->id, 'review_date' => '2026-08-01', 'energy' => 8]);
        $backup = $this->backupBytes($source);
        $firstTarget = $this->user('first@example.test');
        $secondTarget = $this->user('second@example.test');
        $validation = $this->validateBytes($firstTarget, $backup)->assertOk();
        $token = $validation->json('data.restore_token');

        $this->restoreBytes($secondTarget, $backup, $token)->assertUnprocessable()
            ->assertJsonPath('errors.backup.0', 'This is not an intact supported SelfHandler backup. [restore_token_invalid]');
        $this->restoreBytes($firstTarget, $backup, substr($token, 0, -1).'x')->assertUnprocessable();
        $this->assertDatabaseMissing('daily_reviews', ['user_id' => $firstTarget->id]);
        $this->assertDatabaseMissing('daily_reviews', ['user_id' => $secondTarget->id]);
    }

    public function test_empty_target_is_rechecked_under_restore_and_existing_data_wins(): void
    {
        $source = $this->user('source@example.test');
        DailyReview::query()->create(['user_id' => $source->id, 'review_date' => '2026-08-01', 'energy' => 8]);
        $backup = $this->backupBytes($source);
        $target = $this->user('target@example.test');
        $validation = $this->validateBytes($target, $backup)->assertOk();
        DailyReview::query()->create(['user_id' => $target->id, 'review_date' => '2026-08-02', 'energy' => 2]);

        $this->restoreBytes($target, $backup, $validation->json('data.restore_token'))->assertConflict();
        $this->assertDatabaseCount('daily_reviews', 2);
        $this->assertDatabaseMissing('daily_reviews', ['user_id' => $target->id, 'review_date' => '2026-08-01']);
    }

    public function test_unsupported_version_unsafe_member_and_checksum_tamper_are_rejected(): void
    {
        $source = $this->user('source@example.test');
        DailyReview::query()->create(['user_id' => $source->id, 'review_date' => '2026-08-01', 'energy' => 8]);
        $original = $this->backupBytes($source);
        $target = $this->user('target@example.test');

        $unsupported = $this->mutate($original, function (ZipArchive $zip): void {
            $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $manifest['schema_version'] = 2;
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        });
        $unsafe = $this->mutate($original, fn (ZipArchive $zip) => $zip->addFromString('../escape.json', '{}'));
        $checksum = $this->mutate($original, fn (ZipArchive $zip) => $zip->addFromString('data/records.json', '{}'));

        foreach ([$unsupported, $unsafe, $checksum] as $archive) {
            $this->validateBytes($target, $archive)->assertUnprocessable();
        }
        $this->assertDatabaseMissing('daily_reviews', ['user_id' => $target->id]);
    }

    public function test_closed_json_symlink_and_member_bounds_are_rejected_before_any_write(): void
    {
        $source = $this->user('source@example.test');
        DailyReview::query()->create(['user_id' => $source->id, 'review_date' => '2026-08-01', 'energy' => 8]);
        $original = $this->backupBytes($source);
        $target = $this->user('target@example.test');

        $closedJson = $this->mutate($original, function (ZipArchive $zip): void {
            $profile = json_decode($zip->getFromName('data/profile.json'), true, flags: JSON_THROW_ON_ERROR);
            $profile['unexpected'] = 'closed';
            $this->replaceDeclaredJsonMember($zip, 'data/profile.json', $profile);
        });
        $symlink = $this->mutate($original, function (ZipArchive $zip): void {
            $this->assertTrue($zip->addFromString('link', 'target'));
            $this->assertTrue($zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16));
        });

        $this->validateBytes($target, $closedJson)->assertUnprocessable()
            ->assertJsonPath('errors.backup.0', 'This is not an intact supported SelfHandler backup. [schema_fields_invalid]');
        $this->validateBytes($target, $symlink)->assertUnprocessable()
            ->assertJsonPath('errors.backup.0', 'This is not an intact supported SelfHandler backup. [member_symlink]');

        config()->set('portability.max_members', 3);
        $tooMany = $this->mutate($original, fn (ZipArchive $zip) => $zip->addFromString('extra', 'bounded'));
        $this->validateBytes($target, $tooMany)->assertUnprocessable()
            ->assertJsonPath('errors.backup.0', 'This is not an intact supported SelfHandler backup. [member_limit_invalid]');
        $this->assertDatabaseMissing('daily_reviews', ['user_id' => $target->id]);
    }

    public function test_restore_requires_literal_confirmation_and_closed_request_fields(): void
    {
        $source = $this->user('source@example.test');
        DailyReview::query()->create(['user_id' => $source->id, 'review_date' => '2026-08-01', 'energy' => 8]);
        $backup = $this->backupBytes($source);
        $target = $this->user('target@example.test');
        $token = $this->validateBytes($target, $backup)->assertOk()->json('data.restore_token');

        foreach ([
            ['confirmation' => 'restore'],
            ['confirmation' => 'RESTORE', 'unexpected' => 'field'],
        ] as $fields) {
            $this->actingAs($target)->post('/api/portability/restore', [
                'backup' => UploadedFile::fake()->createWithContent('backup.zip', $backup),
                'restore_token' => $token,
                ...$fields,
            ], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertDatabaseMissing('daily_reviews', ['user_id' => $target->id]);
    }

    public function test_storage_failure_rolls_back_rows_and_compensates_prior_private_write(): void
    {
        $source = $this->user('source@example.test');
        $measurement = $this->measurement($source);
        $meal = $this->meal($source);
        $service = app(AttachmentService::class);
        $service->upload($source, 'body_measurement', $measurement->id, 'body-source', $this->image());
        $service->upload($source, 'meal', $meal->id, 'meal-source', $this->image('meal.png'));
        $backupBytes = $this->backupBytes($source);
        $upload = UploadedFile::fake()->createWithContent('backup.zip', $backupBytes);
        $backup = app(PortableBackupReader::class)->read($upload);
        $target = $this->user('target@example.test');
        $token = app(RestoreTokenService::class)->issue($target, $backup->archiveSha256)['token'];

        $storage = Mockery::mock(FileStorage::class);
        $storage->shouldReceive('pathFor')->twice()->andReturn(
            "attachments/{$target->id}/00000000-0000-4000-8000-000000000001.png",
            "attachments/{$target->id}/00000000-0000-4000-8000-000000000002.png",
        );
        $storage->shouldReceive('put')->once()->with(
            "attachments/{$target->id}/00000000-0000-4000-8000-000000000001.png", Mockery::type('string'),
        );
        $storage->shouldReceive('diskName')->once()->andReturn('local');
        $storage->shouldReceive('put')->once()->with(
            "attachments/{$target->id}/00000000-0000-4000-8000-000000000002.png", Mockery::type('string'),
        )->andThrow(new AttachmentStorageException('injected'));
        $storage->shouldReceive('delete')->once()->with(
            "attachments/{$target->id}/00000000-0000-4000-8000-000000000001.png",
        );
        $restorer = new PortableBackupRestorer(
            app(RestoreEligibilityService::class), app(RestoreTokenService::class),
            app(PortableBackupReader::class), $storage,
        );

        try {
            $restorer->restore($target, $backup, $token);
            $this->fail('The injected storage failure was not raised.');
        } catch (AttachmentStorageException) {
            $this->assertDatabaseMissing('body_measurements', ['user_id' => $target->id]);
            $this->assertDatabaseMissing('meals', ['user_id' => $target->id]);
            $this->assertDatabaseMissing('attachments', ['user_id' => $target->id]);
        }
    }

    public function test_polymorphic_nullable_and_public_system_references_are_remapped(): void
    {
        $source = $this->user('source@example.test');
        $now = now();
        $routineId = DB::table('routines')->insertGetId([
            'user_id' => $source->id, 'name' => 'Portable routine', 'kind' => 'habit', 'sort_order' => 1,
            'is_active' => true, 'is_archived' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $ruleId = DB::table('recurring_rules')->insertGetId([
            'user_id' => $source->id, 'owner_type' => 'routine', 'owner_id' => $routineId,
            'frequency' => 'daily', 'timezone' => 'UTC', 'interval_count' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $logId = DB::table('routine_logs')->insertGetId([
            'user_id' => $source->id, 'routine_id' => $routineId, 'log_date' => '2026-08-01',
            'status' => 'done', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('planned_occurrences')->insert([
            'user_id' => $source->id, 'recurring_rule_id' => $ruleId, 'occurrence_date' => '2026-08-01',
            'slot' => '', 'status' => 'done', 'routine_log_id' => $logId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $programId = DB::table('workout_programs')->insertGetId([
            'user_id' => $source->id, 'name' => 'Portable program', 'workout_type' => 'strength',
            'intensity' => 'moderate', 'is_active' => true, 'is_archived' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $squatId = DB::table('exercises')->whereNull('user_id')->where('system_key', 'squat')->value('id');
        DB::table('workout_program_exercises')->insert([
            'user_id' => $source->id, 'workout_program_id' => $programId, 'exercise_id' => $squatId,
            'sort_order' => 1, 'target_sets' => 3, 'target_reps' => 5, 'starting_weight_kg' => '20.000',
            'increment_kg' => '2.500', 'successes_required' => 2, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $recipeId = DB::table('recipes')->insertGetId([
            'user_id' => $source->id, 'name' => 'Portable water', 'is_archived' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $waterId = DB::table('food_items')->whereNull('user_id')->where('system_key', 'plain_water')->value('id');
        DB::table('recipe_components')->insert([
            'user_id' => $source->id, 'recipe_id' => $recipeId, 'food_item_id' => $waterId,
            'sort_order' => 1, 'quantity_grams' => '250.000', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $backup = $this->backupBytes($source);
        $target = $this->user('target@example.test');
        $validation = $this->validateBytes($target, $backup)->assertOk();
        $this->restoreBytes($target, $backup, $validation->json('data.restore_token'))->assertOk();

        $targetRoutine = DB::table('routines')->where('user_id', $target->id)->first();
        $targetRule = DB::table('recurring_rules')->where('user_id', $target->id)->first();
        $targetLog = DB::table('routine_logs')->where('user_id', $target->id)->first();
        $targetOccurrence = DB::table('planned_occurrences')->where('user_id', $target->id)->first();
        $this->assertSame((int) $targetRoutine->id, (int) $targetRule->owner_id);
        $this->assertSame((int) $targetRule->id, (int) $targetOccurrence->recurring_rule_id);
        $this->assertSame((int) $targetLog->id, (int) $targetOccurrence->routine_log_id);
        $this->assertSame((int) $squatId, (int) DB::table('workout_program_exercises')->where('user_id', $target->id)->value('exercise_id'));
        $this->assertSame((int) $waterId, (int) DB::table('recipe_components')->where('user_id', $target->id)->value('food_item_id'));
        $this->assertNotSame($routineId, (int) $targetRoutine->id);
    }

    private function backupBytes(User $user): string
    {
        $response = $this->actingAs($user)->get('/api/portability/backup')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function validateBytes(User $user, string $bytes)
    {
        return $this->actingAs($user)->post('/api/portability/restore/validate', [
            'backup' => UploadedFile::fake()->createWithContent('backup.zip', $bytes),
        ], ['Accept' => 'application/json']);
    }

    private function restoreBytes(User $user, string $bytes, string $token)
    {
        return $this->actingAs($user)->post('/api/portability/restore', [
            'backup' => UploadedFile::fake()->createWithContent('backup.zip', $bytes),
            'restore_token' => $token, 'confirmation' => 'RESTORE',
        ], ['Accept' => 'application/json']);
    }

    private function mutate(string $bytes, callable $mutation): string
    {
        $path = tempnam(sys_get_temp_dir(), 'portable-mutation-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $mutation($zip);
        $zip->close();
        $mutated = file_get_contents($path);
        @unlink($path);

        return $mutated;
    }

    /** @param array<string,mixed> $payload */
    private function replaceDeclaredJsonMember(ZipArchive $zip, string $path, array $payload): void
    {
        $previousSize = strlen((string) $zip->getFromName($path));
        $bytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertTrue($zip->addFromString($path, $bytes));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['members'] as &$member) {
            if ($member['path'] === $path) {
                $member['size_bytes'] = strlen($bytes);
                $member['sha256'] = hash('sha256', $bytes);
            }
        }
        unset($member);
        $manifest['counts']['total_bytes'] += strlen($bytes) - $previousSize;
        $this->assertTrue($zip->addFromString('manifest.json', json_encode(
            $manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )));
    }
}
