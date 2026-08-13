<?php

namespace App\Exceptions\Attachments;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class AttachmentConflict extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('messages.attachment_upload_conflict')], 409);
    }
}
