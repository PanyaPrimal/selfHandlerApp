<?php

namespace App\Services\Attachments;

use App\Models\Attachment;

final readonly class AttachmentUploadResult
{
    public function __construct(public Attachment $attachment, public bool $created) {}
}
