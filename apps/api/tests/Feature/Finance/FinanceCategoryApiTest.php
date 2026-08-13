<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceCategory;
use Tests\Support\FinanceTestCase;

class FinanceCategoryApiTest extends FinanceTestCase
{
    public function test_starter_list_is_idempotent_and_localized_from_profile(): void
    {
        $owner = $this->owner();
        $english = $this->actingAs($owner)->getJson('/api/finance/categories')->assertOk();
        $englishCount = count($english->json('data'));
        $this->assertGreaterThanOrEqual(10, $englishCount);
        $englishSalary = collect($english->json('data'))->firstWhere('builtin_key', 'income_salary')['label'];

        $owner->ensureProfile()->update(['locale' => 'ru-UA']);
        $russian = $this->actingAs($owner->fresh())->getJson('/api/finance/categories')->assertOk();
        $this->assertCount($englishCount, $russian->json('data'));
        $russianSalary = collect($russian->json('data'))->firstWhere('builtin_key', 'income_salary')['label'];
        $this->assertSame('Salary', $englishSalary);
        $this->assertSame('Зарплата', $russianSalary);
    }

    public function test_custom_root_and_child_crud_enforces_direction_depth_and_history(): void
    {
        $owner = $this->owner();
        $root = $this->actingAs($owner)->postJson('/api/finance/categories', [
            'direction' => 'expense', 'name' => 'Home',
        ])->assertCreated()->json('data');
        $child = $this->actingAs($owner)->postJson('/api/finance/categories', [
            'direction' => 'expense', 'parent_id' => $root['id'], 'name' => 'Repairs',
        ])->assertCreated()->assertJsonPath('data.label', 'Repairs')->json('data');

        $this->actingAs($owner)->postJson('/api/finance/categories', [
            'direction' => 'expense', 'parent_id' => $child['id'], 'name' => 'Paint',
        ])->assertUnprocessable();
        $this->actingAs($owner)->postJson('/api/finance/categories', [
            'direction' => 'income', 'parent_id' => $root['id'], 'name' => 'Mismatch',
        ])->assertUnprocessable();

        $this->actingAs($owner)->patchJson("/api/finance/categories/{$child['id']}", [
            'name' => 'Maintenance', 'archived' => true,
        ])->assertOk()->assertJsonPath('data.archived', true)->assertJsonPath('data.label', 'Maintenance');
        $this->actingAs($owner)->patchJson("/api/finance/categories/{$child['id']}", ['archived' => false])
            ->assertOk()->assertJsonPath('data.archived', false);

        $category = FinanceCategory::query()->findOrFail($child['id']);
        $account = $this->account($owner);
        $this->entry($owner, $account, '-1.0000', 'expense', $category);
        $this->actingAs($owner)->patchJson("/api/finance/categories/{$child['id']}", ['parent_id' => null])
            ->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/finance/categories')
            ->assertOk()->assertJsonFragment(['id' => $child['id'], 'used' => true]);
    }

    public function test_category_requests_are_closed_unique_and_owner_scoped(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $category = $this->category($owner);

        $this->actingAs($other)->patchJson("/api/finance/categories/{$category->id}", ['name' => 'Taken'])
            ->assertNotFound();
        $this->actingAs($owner)->postJson('/api/finance/categories', [
            'direction' => 'expense', 'name' => ' Utilities ',
        ])->assertCreated();
        $this->actingAs($owner)->postJson('/api/finance/categories', [
            'direction' => 'expense', 'name' => 'utilities',
        ])->assertUnprocessable();
        $this->actingAs($owner)->postJson('/api/finance/categories', [
            'direction' => 'expense', 'name' => 'Valid', 'extra' => true,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('request');
    }
}
