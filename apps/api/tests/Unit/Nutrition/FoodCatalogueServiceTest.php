<?php

namespace Tests\Unit\Nutrition;

use App\Services\FoodCatalogueService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Nutrition\NutritionTestCase;

class FoodCatalogueServiceTest extends NutritionTestCase
{
    public function test_catalogue_is_exactly_public_or_owned_and_mutations_are_owner_only(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $mine = $this->createSolid($owner);
        $theirs = $this->createSolid($other, ['name' => 'Other grain']);
        $service = app(FoodCatalogueService::class);

        $this->assertEqualsCanonicalizing([$this->water()->id, $mine->id], $service->list($owner)->pluck('id')->all());
        $this->assertNotContains($theirs->id, $service->list($owner, 'all')->pluck('id')->all());

        $this->expectException(ModelNotFoundException::class);
        $service->update($theirs, $owner, ['name' => 'Leak']);
    }

    public function test_plain_water_is_immutable_and_exact(): void
    {
        $owner = $this->createUser();
        $water = $this->water();

        $this->assertSame('0.000', $water->protein_per_100);
        $this->assertSame('0.000', $water->fat_per_100);
        $this->assertSame('0.000', $water->carbs_per_100);
        $this->assertNull($water->quality_score);

        $this->expectException(ModelNotFoundException::class);
        app(FoodCatalogueService::class)->update($water, $owner, ['name' => 'Changed']);
    }

    public function test_solid_and_beverage_invariants_are_strict(): void
    {
        $owner = $this->createUser();
        $service = app(FoodCatalogueService::class);
        $invalid = [
            ['basis_unit' => 'millilitre', 'is_beverage' => false, 'hydration_ratio' => 0],
            ['basis_unit' => 'gram', 'is_beverage' => true, 'hydration_ratio' => 0.8],
            ['basis_unit' => 'gram', 'is_beverage' => false, 'hydration_ratio' => 0.1],
            ['basis_unit' => 'millilitre', 'is_beverage' => true, 'hydration_ratio' => 1.1],
        ];

        foreach ($invalid as $fields) {
            try {
                $service->create($owner, [
                    'name' => 'Invalid '.json_encode($fields), 'calories_per_100' => 0,
                    'protein_per_100' => 0, 'fat_per_100' => 0, 'carbs_per_100' => 0,
                    'quality_score' => null, ...$fields,
                ]);
                $this->fail('Expected strict basis validation.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_archive_restore_filters_and_preserves_existing_reference(): void
    {
        $owner = $this->createUser();
        $food = $this->createSolid($owner);
        $service = app(FoodCatalogueService::class);

        $service->update($food, $owner, ['is_archived' => true]);
        $this->assertNotContains($food->id, $service->list($owner)->pluck('id')->all());
        $this->assertContains($food->id, $service->list($owner, 'archived')->pluck('id')->all());
        $this->assertNotNull($food->fresh()->archived_at);

        $service->update($food->fresh(), $owner, ['is_archived' => false]);
        $this->assertContains($food->id, $service->list($owner)->pluck('id')->all());
        $this->assertNull($food->fresh()->archived_at);
    }
}
