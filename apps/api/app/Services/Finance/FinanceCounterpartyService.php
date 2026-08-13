<?php

namespace App\Services\Finance;

use App\Models\FinanceCounterparty;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FinanceCounterpartyService
{
    /** @return Collection<int, FinanceCounterparty> */
    public function list(User $user, bool $archived = false): Collection
    {
        return FinanceCounterparty::query()->ownedBy($user)->where('is_archived', $archived)
            ->orderBy('name')->orderBy('id')->get();
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): FinanceCounterparty
    {
        $this->assertUnique($user, (string) $data['name']);

        return FinanceCounterparty::query()->create([
            'user_id' => $user->id, 'name' => trim((string) $data['name']), 'kind' => $data['kind'],
            'note' => $this->nullableTrim($data['note'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, FinanceCounterparty $counterparty, array $data): FinanceCounterparty
    {
        abort_unless($counterparty->isOwnedBy($user), 404);
        if (array_key_exists('name', $data)) {
            $this->assertUnique($user, (string) $data['name'], $counterparty->id);
        }
        if (($data['archived'] ?? false) && $counterparty->debts()->where('is_archived', false)->exists()) {
            throw ValidationException::withMessages(['archived' => __('messages.finance_counterparty_in_use')]);
        }
        $counterparty->fill(array_filter([
            'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : null,
            'kind' => $data['kind'] ?? null,
            'note' => array_key_exists('note', $data) ? $this->nullableTrim($data['note']) : null,
        ], fn ($value, $key): bool => array_key_exists($key, $data), ARRAY_FILTER_USE_BOTH));
        if (array_key_exists('archived', $data)) {
            $counterparty->is_archived = (bool) $data['archived'];
            $counterparty->archived_at = $data['archived'] ? ($counterparty->archived_at ?? now()) : null;
        }
        $counterparty->save();

        return $counterparty->fresh();
    }

    private function assertUnique(User $user, string $name, ?int $except = null): void
    {
        $query = FinanceCounterparty::query()->ownedBy($user)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))]);
        if ($except !== null) {
            $query->whereKeyNot($except);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => __('messages.finance_counterparty_duplicate')]);
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        return $value === null ? null : trim((string) $value);
    }
}
