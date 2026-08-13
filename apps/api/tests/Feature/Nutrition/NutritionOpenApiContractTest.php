<?php

namespace Tests\Feature\Nutrition;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

class NutritionOpenApiContractTest extends NutritionTestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        $path = base_path('../../specs/016-nutrition-meals-hydration-targets/contracts/openapi.yaml');
        $this->assertFileExists($path);

        return Yaml::parseFile($path);
    }

    public function test_contract_is_openapi_31_with_thirteen_unique_authenticated_operations(): void
    {
        $document = $this->document();
        $ids = [];
        foreach ($document['paths'] as $operations) {
            foreach ($operations as $method => $operation) {
                if ($method !== 'parameters') {
                    $ids[] = $operation['operationId'];
                }
            }
        }

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(9, $document['paths']);
        $this->assertCount(13, $ids);
        $this->assertCount(13, array_unique($ids));
        $this->assertSame([['sanctum' => []]], $document['security']);
    }

    public function test_every_local_reference_resolves(): void
    {
        $document = $this->document();
        $references = [];
        $walk = function (mixed $value) use (&$walk, &$references): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($key === '$ref') {
                    $references[] = $child;
                }
                $walk($child);
            }
        };
        $walk($document);

        foreach ($references as $reference) {
            $this->assertStringStartsWith('#/', $reference);
            $node = $document;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                $this->assertArrayHasKey($segment, $node, $reference);
                $node = $node[$segment];
            }
        }
        $this->assertGreaterThan(150, count($references));
    }

    public function test_every_mutation_and_domain_object_schema_is_closed(): void
    {
        $schemas = $this->document()['components']['schemas'];
        foreach ([
            'FoodWriteRequest', 'FoodUpdateRequest', 'RecipeComponentWrite', 'RecipeWriteRequest',
            'RecipeUpdateRequest', 'SettingsWriteRequest', 'MealEntryWrite', 'MealWriteRequest',
            'MealUpdateRequest', 'Food', 'RecipeComponent', 'NutritionPer100', 'Recipe',
            'NutritionSettings', 'MealEntry', 'Meal', 'TargetBasis', 'NutritionTarget',
            'NutritionRefinement', 'ProgressValue', 'NutritionProgress', 'NutritionSummary',
            'NutritionDay', 'SummaryRange',
        ] as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties'], "{$name} must be closed.");
        }
    }

    public function test_documented_and_registered_feature_operations_match_exactly(): void
    {
        $documented = [];
        foreach ($this->document()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if ($method !== 'parameters') {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (preg_match('#^api/nutrition(?:/|$)#', $route->uri()) !== 1) {
                continue;
            }
            $registered[] = strtoupper($route->methods()[0]).' /'.$route->uri();
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }

        sort($documented);
        sort($registered);
        $this->assertSame($documented, $registered);
    }

    public function test_existing_contracts_document_nutrition_today_summary_and_workout_planned_energy(): void
    {
        $core = Yaml::parseFile(base_path('../../specs/001-core-daily-loop/contracts/openapi.yaml'));
        $workouts = Yaml::parseFile(base_path('../../specs/015-workouts-training-goals/contracts/openapi.yaml'));

        $this->assertArrayHasKey(
            'nutrition',
            $core['components']['schemas']['TodayResponse']['properties']['module_summaries']['properties'],
        );
        $this->assertArrayHasKey(
            'planned_energy_kcal',
            $workouts['components']['schemas']['WorkoutProgram']['properties'],
        );
    }
}
