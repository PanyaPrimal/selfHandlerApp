<?php

namespace Tests\Feature\Attachments;

use App\Exceptions\Attachments\AttachmentStorageException;
use App\Models\Attachment;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\FileStorage;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\Support\AttachmentTestCase;

class AttachmentApiTest extends AttachmentTestCase
{
    public function test_routes_require_authentication(): void
    {
        foreach ([
            ['post', '/api/attachments'],
            ['get', '/api/attachments/1/content'],
            ['delete', '/api/attachments/1'],
        ] as [$method, $uri]) {
            $this->json(strtoupper($method), $uri)->assertUnauthorized();
        }
    }

    public function test_owner_can_upload_stream_and_delete_private_normalized_photo(): void
    {
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        $this->actingAs($owner);

        $created = $this->post($this->uploadPath('body_measurement', $measurement->id, 'api-key'), [
            'file' => $this->image('../../phone.png'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.kind', 'photo')
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk')
            ->assertJsonMissingPath('data.sha256');

        $id = $created->json('data.id');
        $attachment = Attachment::query()->findOrFail($id);
        $bytes = \Storage::disk('local')->get($attachment->path);
        $stream = $this->get("/api/attachments/{$id}/content")->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Length', (string) strlen($bytes))
            ->assertHeader('Content-Disposition', "inline; filename=photo.png; filename*=utf-8''phone.png")
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', 'sandbox');
        $this->assertSame($bytes, $stream->streamedContent());

        $this->deleteJson("/api/attachments/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('attachments', ['id' => $id]);
        \Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_idempotent_api_replay_returns_200_and_conflict_returns_409(): void
    {
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        $this->actingAs($owner);
        $path = $this->uploadPath('body_measurement', $measurement->id, 'same-key');

        $id = $this->post($path, ['file' => $this->image()], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->post($path, ['file' => $this->image()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.id', $id);

        $meal = $this->meal($owner);
        $this->post($this->uploadPath('meal', $meal->id, 'same-key'), [
            'file' => $this->image(),
        ], ['Accept' => 'application/json'])->assertConflict();
        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_foreign_content_and_delete_are_indistinguishable_from_missing(): void
    {
        $owner = $this->user();
        $foreign = $this->user('foreign@example.test');
        $measurement = $this->measurement($foreign);
        $this->actingAs($foreign);
        $id = $this->post($this->uploadPath('body_measurement', $measurement->id, 'foreign-key'), [
            'file' => $this->image(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->actingAs($owner);
        $this->getJson("/api/attachments/{$id}/content")->assertNotFound();
        $this->deleteJson("/api/attachments/{$id}")->assertNotFound();
        $this->getJson('/api/attachments/999999/content')->assertNotFound();
        $this->assertDatabaseHas('attachments', ['id' => $id]);
    }

    public function test_upload_contract_rejects_unknown_fields_types_and_oversized_content(): void
    {
        $owner = $this->user();
        $measurement = $this->measurement($owner);
        $this->actingAs($owner);

        $this->post('/api/attachments?'.http_build_query([
            'attachable_type' => 'workout', 'attachable_id' => $measurement->id,
            'upload_key' => 'x', 'unexpected' => 'leak',
        ]), ['file' => $this->image()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['attachable_type', 'request']);

        $this->post($this->uploadPath('body_measurement', $measurement->id, 'fake'), [
            'file' => UploadedFile::fake()->create('fake.jpg', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['file']);
    }

    public function test_storage_failure_returns_localized_safe_service_error(): void
    {
        $owner = $this->user();
        $owner->ensureProfile()->update(['locale' => 'uk-UA']);
        $measurement = $this->measurement($owner);
        $attachment = app(AttachmentService::class)
            ->upload($owner, 'body_measurement', $measurement->id, 'storage-error', $this->image())->attachment;
        $storage = Mockery::mock(FileStorage::class);
        $storage->shouldReceive('exists')->once()->with($attachment->path)
            ->andThrow(new AttachmentStorageException('private internal detail'));
        app()->instance(FileStorage::class, $storage);
        $this->actingAs($owner);

        $response = $this->getJson("/api/attachments/{$attachment->id}/content", [
            'Accept-Language' => 'uk',
        ])->assertServiceUnavailable()
            ->assertJsonPath('message', trans('messages.attachment_storage_unavailable', [], 'uk'));
        $this->assertStringNotContainsString('private internal detail', $response->getContent());
        $this->assertStringNotContainsString($attachment->path, $response->getContent());
    }
}
