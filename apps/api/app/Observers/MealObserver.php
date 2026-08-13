<?php

namespace App\Observers;

use App\Models\Meal;
use App\Services\Attachments\AttachmentService;

class MealObserver
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function deleting(Meal $meal): void
    {
        $this->attachments->deleteForParent($meal);
    }
}
