<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;

class ListInvitations extends Command
{
    /**
     * @var string
     */
    protected $signature = 'invite:list {--unused : Show only codes that have not been used yet}';

    /**
     * @var string
     */
    protected $description = 'List registration invite codes and their status';

    public function handle(): int
    {
        $query = Invitation::query()->with('usedBy')->latest('id');

        if ($this->option('unused')) {
            $query->whereNull('used_at');
        }

        $invitations = $query->get();

        if ($invitations->isEmpty()) {
            $this->info('No invite codes found. Create one with: php artisan invite:create');

            return self::SUCCESS;
        }

        $rows = $invitations->map(fn (Invitation $invite): array => [
            $invite->code,
            $invite->note ?? '—',
            $invite->isUsed() ? 'used' : 'available',
            $invite->usedBy?->email ?? '—',
            $invite->used_at?->format('Y-m-d H:i') ?? '—',
        ])->all();

        $this->table(['Code', 'Note', 'Status', 'Used by', 'Used at'], $rows);

        return self::SUCCESS;
    }
}
