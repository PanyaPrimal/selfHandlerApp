<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Attachments\AttachmentService;

class UserAttachmentObserver
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function deleting(User $user): void
    {
        $this->attachments->deleteForUser($user);
    }
}
