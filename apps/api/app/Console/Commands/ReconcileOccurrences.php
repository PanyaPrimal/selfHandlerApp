<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OccurrenceFactSynchronizer;
use Illuminate\Console\Command;

class ReconcileOccurrences extends Command
{
    protected $signature = 'recurrence:reconcile {--user= : Limit the run to one user id}';

    protected $description = 'Rebuild derived planned-occurrence status from the routine logs';

    public function handle(OccurrenceFactSynchronizer $synchronizer): int
    {
        $users = User::query()
            ->when($this->option('user'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($users as $user) {
            $total += $synchronizer->reconcile($user);
        }

        $this->info("Reconciled {$total} occurrence(s) for {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
