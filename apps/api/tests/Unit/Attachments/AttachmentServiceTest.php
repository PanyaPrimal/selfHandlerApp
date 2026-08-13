<?php

namespace Tests\Unit\Attachments;

use App\Exceptions\Attachments\AttachmentConflict;
use App\Exceptions\Attachments\AttachmentStorageException;
use App\Models\Attachment;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\FileStorage;
use App\Services\Attachments\ImageNormalizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Support\AttachmentTestCase;

class AttachmentServiceTest extends AttachmentTestCase
{
    public function test_upload_is_idempotent_for_same_owner_parent_key_and_normalized_content(): void
    {
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        $service = app(AttachmentService::class);

        $first = $service->upload($owner, 'body_measurement', $measurement->id, 'stable-key', $this->image());
        $second = $service->upload($owner, 'body_measurement', $measurement->id, 'stable-key', $this->image());

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertTrue($first->attachment->is($second->attachment));
        $this->assertDatabaseCount('attachments', 1);
        $this->assertCount(1, \Storage::disk('local')->allFiles('attachments'));
    }

    public function test_upload_key_conflict_never_mutates_or_writes_another_file(): void
    {
        $owner = $this->user();
        $firstParent = $this->measurement($owner);
        $secondParent = $this->measurement($owner, ['metric' => 'waist']);
        $service = app(AttachmentService::class);
        $service->upload($owner, 'body_measurement', $firstParent->id, 'stable-key', $this->image());

        try {
            $service->upload($owner, 'body_measurement', $secondParent->id, 'stable-key', $this->image());
            $this->fail('A retry identity was reused for another parent.');
        } catch (AttachmentConflict) {
            $this->assertDatabaseCount('attachments', 1);
            $this->assertCount(1, \Storage::disk('local')->allFiles('attachments'));
        }
    }

    public function test_parent_and_owner_quota_are_exact_and_replay_is_still_allowed(): void
    {
        config(['attachments.max_per_parent' => 1]);
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        $service = app(AttachmentService::class);
        $first = $service->upload($owner, 'body_measurement', $measurement->id, 'one', $this->image());

        $replay = $service->upload($owner, 'body_measurement', $measurement->id, 'one', $this->image());
        $this->assertTrue($first->attachment->is($replay->attachment));

        try {
            $service->upload($owner, 'body_measurement', $measurement->id, 'two', $this->image('other.png'));
            $this->fail('Parent quota was exceeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        config(['attachments.max_per_parent' => 10, 'attachments.max_bytes_per_user' => $first->attachment->size_bytes]);
        $meal = $this->meal($owner);
        $this->expectException(ValidationException::class);
        $service->upload($owner, 'meal', $meal->id, 'owner-full', $this->image('meal.webp'));
    }

    public function test_tenth_parent_photo_is_accepted_and_eleventh_leaves_no_new_file(): void
    {
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        foreach (range(1, 9) as $index) {
            Attachment::factory()->forBodyMeasurement($measurement)->create([
                'user_id' => $owner->id, 'upload_key' => "parent-existing-{$index}",
            ]);
        }

        $service = app(AttachmentService::class);
        $tenth = $service->upload($owner, 'body_measurement', $measurement->id, 'parent-tenth', $this->image());
        $this->assertTrue($tenth->created);
        $this->assertSame(10, $measurement->attachments()->count());
        $files = Storage::disk('local')->allFiles('attachments');

        try {
            $service->upload($owner, 'body_measurement', $measurement->id, 'parent-eleventh', $this->image());
            $this->fail('An eleventh parent photo was accepted.');
        } catch (ValidationException) {
            $this->assertSame(10, $measurement->attachments()->count());
            $this->assertSame($files, Storage::disk('local')->allFiles('attachments'));
        }

        $meal = $this->meal($owner);
        foreach (range(1, 9) as $index) {
            Attachment::factory()->forMeal($meal)->create([
                'user_id' => $owner->id, 'upload_key' => "meal-parent-existing-{$index}",
            ]);
        }
        $service->upload($owner, 'meal', $meal->id, 'meal-parent-tenth', $this->image());
        $this->assertSame(10, $meal->attachments()->count());
        $files = Storage::disk('local')->allFiles('attachments');
        try {
            $service->upload($owner, 'meal', $meal->id, 'meal-parent-eleventh', $this->image());
            $this->fail('An eleventh Meal photo was accepted.');
        } catch (ValidationException) {
            $this->assertSame(10, $meal->attachments()->count());
            $this->assertSame($files, Storage::disk('local')->allFiles('attachments'));
        }
    }

    public function test_exact_one_hundred_mebibyte_owner_boundary_and_replay_are_atomic(): void
    {
        $owner = $this->user();
        $body = $this->measurement($owner);
        $meal = $this->meal($owner);
        $remainderParent = $this->measurement($owner, ['metric' => 'waist']);
        $overflowParent = $this->measurement($owner, ['metric' => 'chest']);
        $probeUpload = $this->image();
        $probe = app(ImageNormalizer::class)->normalize($probeUpload);
        $incomingBytes = $probe->sizeBytes;
        $probe->release();
        @unlink($probeUpload->getPathname());
        $fiveMebibytes = 5 * 1024 * 1024;

        foreach (range(1, 10) as $index) {
            Attachment::factory()->forBodyMeasurement($body)->create([
                'size_bytes' => $fiveMebibytes, 'upload_key' => "owner-body-{$index}",
            ]);
        }
        foreach (range(1, 9) as $index) {
            Attachment::factory()->forMeal($meal)->create([
                'size_bytes' => $fiveMebibytes, 'upload_key' => "owner-meal-{$index}",
            ]);
        }
        Attachment::factory()->forBodyMeasurement($remainderParent)->create([
            'size_bytes' => $fiveMebibytes - $incomingBytes,
            'upload_key' => 'owner-remainder',
        ]);

        $service = app(AttachmentService::class);
        $exact = $service->upload(
            $owner, 'body_measurement', $remainderParent->id, 'owner-exact', $this->image(),
        );
        $this->assertSame(100 * 1024 * 1024, (int) Attachment::query()->ownedBy($owner)->sum('size_bytes'));
        $this->assertTrue($exact->attachment->is(
            $service->upload($owner, 'body_measurement', $remainderParent->id, 'owner-exact', $this->image())->attachment,
        ));

        $files = Storage::disk('local')->allFiles('attachments');
        try {
            $service->upload($owner, 'body_measurement', $overflowParent->id, 'owner-overflow', $this->image());
            $this->fail('The owner byte quota accepted one more normalized image.');
        } catch (ValidationException) {
            $this->assertSame(100 * 1024 * 1024, (int) Attachment::query()->ownedBy($owner)->sum('size_bytes'));
            $this->assertSame($files, Storage::disk('local')->allFiles('attachments'));
        }
    }

    public function test_database_failure_after_private_write_compensates_final_file_and_row(): void
    {
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        Attachment::creating(static function (): never {
            throw new RuntimeException('forced persistence failure');
        });

        try {
            app(AttachmentService::class)->upload(
                $owner, 'body_measurement', $measurement->id, 'forced-failure', $this->image(),
            );
            $this->fail('The forced persistence failure did not escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced persistence failure', $exception->getMessage());
            $this->assertDatabaseCount('attachments', 0);
            $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
        }
    }

    public function test_partial_final_write_failure_compensates_bytes_and_never_creates_metadata(): void
    {
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        $path = "attachments/{$owner->id}/11111111-1111-4111-8111-111111111111.png";
        $storage = Mockery::mock(FileStorage::class);
        $storage->shouldReceive('pathFor')->once()->with($owner, 'png')->andReturn($path);
        $storage->shouldReceive('put')->once()->with($path, Mockery::type('string'))
            ->andReturnUsing(function () use ($path): never {
                Storage::disk('local')->put($path, 'partial-private-bytes');
                throw new AttachmentStorageException('forced partial write failure');
            });
        $storage->shouldReceive('delete')->once()->with($path)->andReturnUsing(
            static fn () => Storage::disk('local')->delete($path),
        );

        try {
            (new AttachmentService($storage, app(ImageNormalizer::class)))->upload(
                $owner, 'body_measurement', $measurement->id, 'partial-write', $this->image(),
            );
            $this->fail('The forced partial write failure did not escape.');
        } catch (AttachmentStorageException) {
            $this->assertDatabaseCount('attachments', 0);
            $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
        }
    }

    public function test_successful_delete_releases_derived_owner_quota_immediately(): void
    {
        $owner = $this->user();
        $body = $this->measurement($owner);
        $meal = $this->meal($owner);
        $service = app(AttachmentService::class);
        $first = $service->upload($owner, 'body_measurement', $body->id, 'quota-delete', $this->image())->attachment;
        config(['attachments.max_bytes_per_user' => $first->size_bytes]);

        try {
            $service->upload($owner, 'meal', $meal->id, 'quota-blocked', $this->image());
            $this->fail('A full owner quota accepted another photo.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('attachments', 1);
        }

        $service->delete($owner, $first->id);
        $replacement = $service->upload($owner, 'meal', $meal->id, 'quota-released', $this->image());
        $this->assertTrue($replacement->created);
        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_foreign_or_unknown_parent_creates_no_metadata_or_bytes(): void
    {
        $owner = $this->user();
        $foreign = $this->user('foreign@example.test');
        $measurement = $this->measurement($foreign);

        try {
            app(AttachmentService::class)->upload(
                $owner, 'body_measurement', $measurement->id, 'foreign', $this->image(),
            );
            $this->fail('A foreign parent was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('attachments', 0);
            $this->assertSame([], \Storage::disk('local')->allFiles('attachments'));
        }
    }
}
