<?php

namespace Tests\Feature\Attachments;

use App\Exceptions\Attachments\AttachmentStorageException;
use App\Models\Attachment;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\FileStorage;
use App\Services\Attachments\ImageNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\AttachmentTestCase;

class AttachmentCleanupTest extends AttachmentTestCase
{
    public function test_body_meal_and_user_deletion_remove_private_files_and_rows(): void
    {
        $owner = $this->user();
        $body = $this->measurement($owner);
        $meal = $this->meal($owner);
        $service = app(AttachmentService::class);
        $bodyAttachment = $service->upload($owner, 'body_measurement', $body->id, 'body', $this->image())->attachment;
        $mealAttachment = $service->upload($owner, 'meal', $meal->id, 'meal', $this->image('meal.png'))->attachment;

        $body->delete();
        $this->assertDatabaseMissing('attachments', ['id' => $bodyAttachment->id]);
        \Storage::disk('local')->assertMissing($bodyAttachment->path);
        $this->assertDatabaseHas('attachments', ['id' => $mealAttachment->id]);

        $owner->delete();
        $this->assertDatabaseMissing('attachments', ['id' => $mealAttachment->id]);
        \Storage::disk('local')->assertMissing($mealAttachment->path);
    }

    public function test_missing_file_can_be_deleted_to_repair_orphan_metadata(): void
    {
        $owner = $this->user();
        $body = $this->measurement($owner);
        $attachment = app(AttachmentService::class)
            ->upload($owner, 'body_measurement', $body->id, 'missing', $this->image())->attachment;
        \Storage::disk('local')->delete($attachment->path);
        $this->actingAs($owner);

        $this->getJson("/api/attachments/{$attachment->id}/content")->assertNotFound();
        $this->deleteJson("/api/attachments/{$attachment->id}")->assertNoContent();
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    public function test_unsupported_or_orphaned_parent_is_not_streamed(): void
    {
        $owner = $this->user();
        $body = $this->measurement($owner);
        $attachment = app(AttachmentService::class)
            ->upload($owner, 'body_measurement', $body->id, 'orphan', $this->image())->attachment;
        \DB::table('attachments')->where('id', $attachment->id)->update(['attachable_id' => 999999]);
        $this->actingAs($owner);

        $this->getJson("/api/attachments/{$attachment->id}/content")->assertNotFound();
        $this->deleteJson("/api/attachments/{$attachment->id}")->assertNotFound();
    }

    public function test_disk_failure_preserves_explicit_delete_metadata_and_bytes_for_retry(): void
    {
        $owner = $this->user();
        $body = $this->measurement($owner);
        $attachment = app(AttachmentService::class)
            ->upload($owner, 'body_measurement', $body->id, 'delete-failure', $this->image())->attachment;
        $storage = Mockery::mock(FileStorage::class);
        $storage->shouldReceive('delete')->once()->with($attachment->path)
            ->andThrow(new AttachmentStorageException('forced delete failure'));
        $service = new AttachmentService($storage, app(ImageNormalizer::class));

        try {
            $service->delete($owner, $attachment->id);
            $this->fail('The forced disk failure did not abort deletion.');
        } catch (AttachmentStorageException) {
            $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
            \Storage::disk('local')->assertExists($attachment->path);
        }

        config(['attachments.max_bytes_per_user' => $attachment->size_bytes]);
        $meal = $this->meal($owner);
        try {
            app(AttachmentService::class)->upload(
                $owner, 'meal', $meal->id, 'still-charged', $this->image(),
            );
            $this->fail('A failed delete released owner quota.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        }
    }

    public function test_disk_failure_aborts_parent_deletion_and_keeps_retryable_attachment(): void
    {
        $owner = $this->user();
        $body = $this->measurement($owner);
        $attachment = app(AttachmentService::class)
            ->upload($owner, 'body_measurement', $body->id, 'parent-failure', $this->image())->attachment;
        $storage = Mockery::mock(FileStorage::class);
        $storage->shouldReceive('delete')->once()->with($attachment->path)
            ->andThrow(new AttachmentStorageException('forced parent cleanup failure'));
        app()->instance(AttachmentService::class, new AttachmentService($storage, app(ImageNormalizer::class)));

        try {
            $body->delete();
            $this->fail('The parent was deleted after private cleanup failed.');
        } catch (AttachmentStorageException) {
            $this->assertDatabaseHas('body_measurements', ['id' => $body->id]);
            $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
            \Storage::disk('local')->assertExists($attachment->path);
        }
    }

    public function test_disk_failure_aborts_user_deletion_and_keeps_retryable_attachment(): void
    {
        $owner = $this->user();
        $meal = $this->meal($owner);
        $attachment = app(AttachmentService::class)
            ->upload($owner, 'meal', $meal->id, 'user-failure', $this->image())->attachment;
        $storage = Mockery::mock(FileStorage::class);
        $storage->shouldReceive('delete')->once()->with($attachment->path)
            ->andThrow(new AttachmentStorageException('forced user cleanup failure'));
        app()->instance(AttachmentService::class, new AttachmentService($storage, app(ImageNormalizer::class)));

        try {
            $owner->delete();
            $this->fail('The user was deleted after private cleanup failed.');
        } catch (AttachmentStorageException) {
            $this->assertDatabaseHas('users', ['id' => $owner->id]);
            $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
            \Storage::disk('local')->assertExists($attachment->path);
        }
    }

    public function test_user_cleanup_reads_and_deletes_many_private_files_in_bounded_batches(): void
    {
        config(['attachments.cleanup_batch_size' => 2]);
        $owner = $this->user();
        $body = $this->measurement($owner);
        foreach (range(1, 7) as $index) {
            $attachment = Attachment::factory()->forBodyMeasurement($body)->create([
                'size_bytes' => 1, 'upload_key' => "batch-{$index}",
            ]);
            Storage::disk('local')->put($attachment->path, 'x');
        }
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'from "attachments"')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $owner->delete();

        $boundedReads = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'order by "id" asc') && str_contains($sql, 'limit 2')
        );
        $this->assertGreaterThanOrEqual(4, count($boundedReads));
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
    }
}
