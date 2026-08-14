<?php

namespace App\Contracts;

use App\Models\User;

/** A read-only module boundary consumed by Review. */
interface ReviewAggregateSource
{
    public function key(): string;

    /** @return array<string,mixed> */
    public function daily(User $user, string $date): array;

    /** @return array<string,mixed> */
    public function period(User $user, string $from, string $to): array;
}
