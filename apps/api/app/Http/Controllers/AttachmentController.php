<?php

namespace App\Http\Controllers;

use App\Exceptions\Attachments\AttachmentStorageException;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly FileStorage $storage,
    ) {}

    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $metadata = $request->metadata();
        $result = $this->attachments->upload(
            $request->user(),
            $metadata['attachable_type'],
            $metadata['attachable_id'],
            $metadata['upload_key'],
            $request->file('file'),
        );

        return response()->json([
            'data' => AttachmentResource::make($result->attachment)->resolve($request),
        ], $result->created ? 201 : 200);
    }

    public function content(Request $request, int $attachment): StreamedResponse
    {
        $model = $this->attachments->owned($request->user(), $attachment);
        if (! $this->storage->exists($model->path)) {
            abort(404);
        }
        $stream = $this->storage->readStream($model->path);
        if ($this->storage->size($model->path) !== $model->size_bytes) {
            fclose($stream);
            throw new AttachmentStorageException('Private attachment byte count does not match metadata.');
        }
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $model->original_name,
            'photo.'.Attachment::MIME_EXTENSIONS[$model->mime_type],
        );

        return response()->stream(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $model->mime_type,
            'Content-Length' => (string) $model->size_bytes,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }

    public function destroy(Request $request, int $attachment): Response
    {
        $this->attachments->delete($request->user(), $attachment);

        return response()->noContent();
    }
}
