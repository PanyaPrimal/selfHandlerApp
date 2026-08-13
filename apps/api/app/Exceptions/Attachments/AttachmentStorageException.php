<?php

namespace App\Exceptions\Attachments;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class AttachmentStorageException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('messages.attachment_storage_unavailable')], 503);
    }
}
