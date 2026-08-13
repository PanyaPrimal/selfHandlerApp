<?php

namespace Tests\Unit\Attachments;

use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use LogicException;
use Tests\Support\AttachmentTestCase;

class AttachmentModelTest extends AttachmentTestCase
{
    public function test_attachment_is_owner_safe_polymorphic_and_immutable(): void
    {
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        $attachment = Attachment::query()->create([
            'user_id' => $owner->id, 'attachable_type' => 'body_measurement',
            'attachable_id' => $measurement->id, 'disk' => 'local',
            'path' => "attachments/{$owner->id}/11111111-1111-4111-8111-111111111111.jpg", 'original_name' => 'one.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'kind' => 'photo',
            'width' => 10, 'height' => 10, 'sha256' => str_repeat('a', 64), 'upload_key' => 'key-one',
        ]);

        $this->assertTrue($attachment->isOwnedBy($owner));
        $this->assertTrue($attachment->attachable->is($measurement));
        $this->assertTrue($measurement->fresh()->attachments->first()->is($attachment));

        $this->expectException(LogicException::class);
        $attachment->update(['original_name' => 'changed.jpg']);
    }

    public function test_cross_owner_or_unknown_parent_attachment_is_rejected(): void
    {
        $owner = $this->user();
        $foreign = $this->user('foreign@example.test');
        $measurement = $this->measurement($foreign);

        $this->expectException(LogicException::class);
        Attachment::query()->create([
            'user_id' => $owner->id, 'attachable_type' => 'body_measurement',
            'attachable_id' => $measurement->id, 'disk' => 'local',
            'path' => "attachments/{$owner->id}/foreign.jpg", 'original_name' => 'foreign.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'kind' => 'photo',
            'width' => 10, 'height' => 10, 'sha256' => str_repeat('b', 64), 'upload_key' => 'foreign',
        ]);
    }

    public function test_resource_excludes_owner_storage_digest_and_retry_metadata(): void
    {
        $owner = $this->user();
        $attachment = Attachment::factory()->forBodyMeasurement($this->measurement($owner))->create([
            'user_id' => $owner->id,
        ]);
        $data = AttachmentResource::make($attachment)->resolve();

        $this->assertSame([
            'id', 'kind', 'original_name', 'mime_type', 'size_bytes', 'width', 'height',
            'created_at', 'content_url',
        ], array_keys($data));
        $this->assertStringStartsWith('/api/attachments/', $data['content_url']);
        $this->assertArrayNotHasKey('path', $data);
        $this->assertArrayNotHasKey('sha256', $data);
    }
}
