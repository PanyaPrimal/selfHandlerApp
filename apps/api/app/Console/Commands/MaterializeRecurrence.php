<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RecurrenceMaterializer;
use Illuminate\Console\Command;

class MaterializeRecurrence extends Command
{
    protected $signature = 'recurrence:materialize {--user= : Limit the run to one user id}';

    protected $description = 'Extend the planned-occurrence window for active recurrence rules';

    public function handle(RecurrenceMaterializer $materializer): int
    {
        $users = User::query()
            ->when($this->option('user'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($users as $user) {
            $written = $materializer->materializeForUser($user);
            $total += $written;
            $this->line("user {$user->id}: {$written} occurrences in window");
        }

        $this->info("Materialized {$total} occurrences for {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
