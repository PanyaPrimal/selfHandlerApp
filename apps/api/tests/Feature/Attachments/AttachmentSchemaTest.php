<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AttachmentTestCase;

class AttachmentSchemaTest extends AttachmentTestCase
{
    public function test_attachment_table_is_additive_and_mysql_safe(): void
    {
        $this->assertTrue(Schema::hasColumns('attachments', [
            'id', 'user_id', 'attachable_type', 'attachable_id', 'disk', 'path', 'original_name',
            'mime_type', 'size_bytes', 'kind', 'width', 'height', 'sha256', 'upload_key', 'meta',
            'created_at',
        ]));

        foreach (Schema::getIndexes('attachments') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
        }
    }

    public function test_upload_identity_and_private_path_are_database_unique(): void
    {
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        $base = [
            'user_id' => $owner->id, 'attachable_type' => 'body_measurement',
            'attachable_id' => $measurement->id, 'disk' => 'local',
            'path' => "attachments/{$owner->id}/11111111-1111-4111-8111-111111111111.jpg", 'original_name' => 'one.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'kind' => 'photo',
            'width' => 1, 'height' => 1, 'sha256' => str_repeat('a', 64), 'upload_key' => 'upload-one',
        ];
        Attachment::query()->create($base);

        foreach ([
            [...$base, 'path' => "attachments/{$owner->id}/22222222-2222-4222-8222-222222222222.jpg"],
            [...$base, 'upload_key' => 'upload-two'],
        ] as $duplicate) {
            try {
                Attachment::query()->create($duplicate);
                $this->fail('A duplicate upload identity or path was accepted.');
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_user_foreign_key_cascades_even_when_observers_are_bypassed(): void
    {
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        $attachment = Attachment::factory()->forBodyMeasurement($measurement)->create();

        \DB::table('users')->where('id', $owner->id)->delete();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    public function test_rollback_removes_only_021_and_preserves_020_rows_then_reapplies(): void
    {
        $owner = $this->user();
        $counterpartyId = \DB::table('finance_counterparties')->insertGetId([
            'user_id' => $owner->id, 'name' => 'Preserved', 'kind' => 'person',
            'is_archived' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_08_14_060000_create_attachments_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('attachments'));
        $this->assertDatabaseHas('finance_counterparties', ['id' => $counterpartyId]);

        $migration->up();
        $this->assertTrue(Schema::hasTable('attachments'));
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }
}
