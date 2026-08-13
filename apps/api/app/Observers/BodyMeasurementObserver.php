<?php

namespace App\Observers;

use App\Models\BodyMeasurement;
use App\Services\Attachments\AttachmentService;

class BodyMeasurementObserver
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function deleting(BodyMeasurement $measurement): void
    {
        $this->attachments->deleteForParent($measurement);
    }
}
