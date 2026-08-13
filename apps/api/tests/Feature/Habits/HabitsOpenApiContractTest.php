<?php

namespace Tests\Feature\Habits;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class HabitsOpenApiContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        $path = base_path('../../specs/013-habits-anti-habits/contracts/openapi.yaml');
        $this->assertFileExists($path);

        return Yaml::parseFile($path);
    }

    public function test_contract_is_openapi_31_with_seven_unique_authenticated_operations(): void
    {
        $document = $this->document();
        $ids = [];

        foreach ($document['paths'] as $operations) {
            foreach ($operations as $operation) {
                $ids[] = $operation['operationId'];
            }
        }

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(7, $ids);
        $this->assertCount(7, array_unique($ids));
        $this->assertSame([['sanctum' => []]], $document['security']);
    }

    public function test_documented_and_registered_habit_operations_match_exactly(): void
    {
        $documented = [];
        foreach ($this->document()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/habits')) {
                continue;
            }
            $path = '/'.preg_replace('#^api/#', '', $route->uri());
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $registered[] = $method.' '.$path;
                }
            }
        }

        sort($documented);
        sort($registered);
        $this->assertSame($documented, $registered);
    }

    public function test_mutation_schemas_are_closed_and_vocabularies_match_specification(): void
    {
        $schemas = $this->document()['components']['schemas'];

        foreach (['CreateHabitRequest', 'UpdateHabitRequest', 'HabitLogRequest', 'LimitStepInput'] as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties'], "{$name} must reject unknown keys.");
        }

        $this->assertSame(['habit', 'anti_habit'], $schemas['HabitKind']['enum']);
        $this->assertSame(['yes_no', 'numeric', 'abstinence', 'stepped_limit'], $schemas['HabitMode']['enum']);
        $this->assertSame(
            ['done', 'not_done', 'recorded', 'protected', 'relapse', 'skipped'],
            $schemas['HabitLogRequest']['properties']['outcome']['enum'],
        );
    }
}
