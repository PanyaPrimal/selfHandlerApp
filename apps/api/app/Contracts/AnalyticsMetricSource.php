<?php

namespace App\Contracts;

use App\Models\User;

/** A read-only aggregate-series boundary implemented beside each owning module. */
interface AnalyticsMetricSource
{
    /** @return list<string> */
    public function keys(): array;

    /**
     * @param  list<string>  $keys
     * @return array<string,list<array<string,mixed>>>
     */
    public function daily(User $user, string $from, string $to, array $keys): array;
}
