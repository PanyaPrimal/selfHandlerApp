<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notifications\DailyDigestBuilder;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEscalator;
use App\Services\Notifications\NotificationSourceSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessUserNotifications implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 55;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(
        NotificationSourceSynchronizer $sources,
        DailyDigestBuilder $digest,
        NotificationEscalator $escalator,
        NotificationDispatcher $dispatcher,
    ): void {
        $user = User::query()->find($this->userId);

        if (! $user) {
            return;
        }

        $sources->synchronize($user);
        $digest->build($user);
        $escalator->scheduleForUser($user);
        $dispatcher->dispatchForUser($user);
    }
}
