<?php

namespace App\Services\Finance;

use App\Models\FinanceTransactionGroup;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

class FinanceIdempotency
{
    /** @param array<string, mixed> $payload */
    public function hash(array $payload): string
    {
        return hash('sha256', (string) json_encode(
            $this->normalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @param array<string, mixed> $payload */
    public function existing(User $user, string $key, array $payload): ?FinanceTransactionGroup
    {
        $group = FinanceTransactionGroup::query()
            ->ownedBy($user)->where('idempotency_key', $key)->lockForUpdate()->first();
        if (! $group) {
            return null;
        }
        if (! hash_equals($group->payload_hash, $this->hash($payload))) {
            throw new HttpResponseException(response()->json([
                'message' => __('messages.finance_idempotency_conflict'),
            ], 409));
        }

        return $group;
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
