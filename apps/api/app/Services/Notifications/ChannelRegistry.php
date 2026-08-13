<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannel;
use InvalidArgumentException;

class ChannelRegistry
{
    public function __construct(private readonly InAppChannel $inApp) {}

    public function get(string $key): NotificationChannel
    {
        if ($key !== $this->inApp->key()) {
            throw new InvalidArgumentException("Unknown notification channel: {$key}");
        }

        return $this->inApp;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return [$this->inApp->key()];
    }
}
