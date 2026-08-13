<?php

namespace App\Console\Commands;

use App\Jobs\ProcessUserNotifications;
use App\Models\User;
use Illuminate\Console\Command;

class ProcessNotifications extends Command
{
    protected $signature = 'notifications:process
        {--user= : Limit processing to one user id}
        {--sync : Run inline for local verification instead of queueing}';

    protected $description = 'Synchronize and deliver due in-app notifications';

    public function handle(): int
    {
        $query = User::query()->select('id')->orderBy('id');

        if ($this->option('user') !== null) {
            $query->whereKey((int) $this->option('user'));
        }

        $count = 0;
        $query->chunkById(100, function ($users) use (&$count): void {
            foreach ($users as $user) {
                if ($this->option('sync')) {
                    app()->call([new ProcessUserNotifications($user->id), 'handle']);
                } else {
                    ProcessUserNotifications::dispatch($user->id);
                }
                $count++;
            }
        });

        $this->info(($this->option('sync') ? 'Processed' : 'Queued')." {$count} user(s).");

        return self::SUCCESS;
    }
}
