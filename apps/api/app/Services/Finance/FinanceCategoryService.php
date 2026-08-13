<?php

namespace App\Services\Finance;

use App\Models\FinanceCategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FinanceCategoryService
{
    /** @var list<array{key:string,direction:string,parent:?string}> */
    public const STARTERS = [
        ['key' => 'income_earned', 'direction' => 'income', 'parent' => null],
        ['key' => 'income_salary', 'direction' => 'income', 'parent' => 'income_earned'],
        ['key' => 'income_freelance', 'direction' => 'income', 'parent' => 'income_earned'],
        ['key' => 'income_other', 'direction' => 'income', 'parent' => null],
        ['key' => 'expense_essential', 'direction' => 'expense', 'parent' => null],
        ['key' => 'expense_housing', 'direction' => 'expense', 'parent' => 'expense_essential'],
        ['key' => 'expense_food', 'direction' => 'expense', 'parent' => 'expense_essential'],
        ['key' => 'expense_transport', 'direction' => 'expense', 'parent' => 'expense_essential'],
        ['key' => 'expense_health', 'direction' => 'expense', 'parent' => 'expense_essential'],
        ['key' => 'expense_lifestyle', 'direction' => 'expense', 'parent' => null],
        ['key' => 'expense_leisure', 'direction' => 'expense', 'parent' => 'expense_lifestyle'],
        ['key' => 'expense_shopping', 'direction' => 'expense', 'parent' => 'expense_lifestyle'],
        ['key' => 'expense_other', 'direction' => 'expense', 'parent' => null],
    ];

    public function ensureStarters(User $user): void
    {
        DB::transaction(function () use ($user): void {
            foreach (self::STARTERS as $definition) {
                $existing = FinanceCategory::query()->ownedBy($user)
                    ->where('builtin_key', $definition['key'])->first();
                if ($existing) {
                    continue;
                }
                $parentId = $definition['parent'] === null ? null : FinanceCategory::query()
                    ->ownedBy($user)->where('builtin_key', $definition['parent'])->value('id');
                try {
                    FinanceCategory::query()->create([
                        'user_id' => $user->id,
                        'direction' => $definition['direction'],
                        'parent_id' => $parentId,
                        'builtin_key' => $definition['key'],
                        'name' => null,
                    ]);
                } catch (QueryException $exception) {
                    if (! FinanceCategory::query()->ownedBy($user)
                        ->where('builtin_key', $definition['key'])->exists()) {
                        throw $exception;
                    }
                }
            }
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): FinanceCategory
    {
        $parent = $this->parent($user, $data['parent_id'] ?? null);
        if ($parent && $parent->direction !== $data['direction']) {
            throw ValidationException::withMessages([
                'parent_id' => __('messages.finance_category_parent_invalid'),
            ]);
        }

        return $this->saveUnique(new FinanceCategory, [
            'user_id' => $user->id,
            'direction' => $data['direction'],
            'parent_id' => $parent?->id,
            'builtin_key' => null,
            'name' => trim($data['name']),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(FinanceCategory $category, User $user, array $data): FinanceCategory
    {
        abort_unless($category->isOwnedBy($user), 404);
        if ($category->builtin_key !== null && (array_key_exists('name', $data) || array_key_exists('parent_id', $data))) {
            throw ValidationException::withMessages([
                'category' => __('messages.finance_category_builtin_locked'),
            ]);
        }

        $attributes = [];
        if (array_key_exists('name', $data)) {
            $attributes['name'] = trim($data['name']);
        }
        if (array_key_exists('parent_id', $data)) {
            $parent = $this->parent($user, $data['parent_id']);
            if ($parent && $parent->direction !== $category->direction) {
                throw ValidationException::withMessages([
                    'parent_id' => __('messages.finance_category_parent_invalid'),
                ]);
            }
            $attributes['parent_id'] = $parent?->id;
        }
        if (array_key_exists('archived', $data)) {
            $attributes['archived_at'] = $data['archived'] ? now() : null;
        }

        try {
            return $this->saveUnique($category, $attributes);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'parent_id' => __('messages.finance_category_used_parent'),
            ]);
        }
    }

    private function parent(User $user, mixed $parentId): ?FinanceCategory
    {
        if ($parentId === null) {
            return null;
        }
        $parent = FinanceCategory::query()->ownedBy($user)->findOrFail((int) $parentId);
        if ($parent->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent_id' => __('messages.finance_category_parent_invalid'),
            ]);
        }

        return $parent;
    }

    /** @param array<string, mixed> $attributes */
    private function saveUnique(FinanceCategory $category, array $attributes): FinanceCategory
    {
        try {
            $category->fill($attributes)->save();
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'name' => __('messages.finance_category_duplicate'),
            ]);
        }

        return $category->fresh()->loadCount('entries');
    }
}
