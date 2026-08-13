<?php

namespace App\Contracts;

use App\Models\InAppNotification;
use App\Models\User;

interface NotificationChannel
{
    public function key(): string;

    public function deliver(InAppNotification $notification, User $recipient): void;
}
