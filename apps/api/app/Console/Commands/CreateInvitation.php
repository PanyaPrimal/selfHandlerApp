<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;

class CreateInvitation extends Command
{
    /**
     * @var string
     */
    protected $signature = 'invite:create
        {--count=1 : How many invite codes to create}
        {--note= : Optional note to remember who a code is for}';

    /**
     * @var string
     */
    protected $description = 'Generate one or more single-use registration invite codes';

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $note = $this->option('note');

        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            // Retry on the rare unique-collision so the command never fails.
            do {
                $code = Invitation::generateCode();
            } while (Invitation::where('code', $code)->exists());

            Invitation::create([
                'code' => $code,
                'note' => $note !== null && $note !== '' ? $note : null,
            ]);

            $codes[] = $code;
        }

        $this->newLine();
        $this->info($count === 1 ? 'Created 1 invite code:' : "Created {$count} invite codes:");
        foreach ($codes as $code) {
            $this->line("  {$code}");
        }
        $this->newLine();
        $this->comment('Each code works once. Share it with the person you want to let register.');

        return self::SUCCESS;
    }
}
