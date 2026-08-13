<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceCategory;
use App\Services\Finance\FinanceCategoryService;
use Tests\Support\FinanceTestCase;

class FinanceCategoryServiceTest extends FinanceTestCase
{
    public function test_starters_materialize_once_with_stable_keys_and_two_levels(): void
    {
        $owner = $this->owner();
        $service = app(FinanceCategoryService::class);

        $service->ensureStarters($owner);
        $first = FinanceCategory::query()->ownedBy($owner)->orderBy('id')->get();
        $service->ensureStarters($owner);
        $second = FinanceCategory::query()->ownedBy($owner)->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(10, $first->count());
        $this->assertSame($first->pluck('builtin_key')->all(), $second->pluck('builtin_key')->all());
        $this->assertSame($first->count(), $first->pluck('builtin_key')->unique()->count());
        foreach ($first as $category) {
            $this->assertNull($category->name);
            if ($category->parent_id) {
                $this->assertNull($first->firstWhere('id', $category->parent_id)->parent_id);
            }
        }
    }
}
