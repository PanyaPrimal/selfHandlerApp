<?php

namespace App\Jobs;

use App\Exceptions\AiAssistantException;
use App\Services\Mentor\MentorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One provider call per job; no automatic paid retry after uncertain delivery. */
class ProcessMentorTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public bool $failOnTimeout = true;

    public function __construct(public int $turnId)
    {
        $this->onQueue('mentor');
    }

    public function handle(MentorService $mentor): void
    {
        try {
            $mentor->processRound($this->turnId);
        } catch (AiAssistantException) {
            // The safe failure and usage are already persisted by the service.
        }
    }

    public function failed(?Throwable $error): void
    {
        DB::table('mentor_turns')->where('id', $this->turnId)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'context' => null, 'error_code' => 'mentor_request_failed', 'updated_at' => now()]);
    }
}
