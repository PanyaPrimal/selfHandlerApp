<?php

namespace Tests\Feature\SleepRoutineTemplates;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SleepRoutineOpenApiContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        $path = base_path('../../specs/014-sleep-routine-templates/contracts/openapi.yaml');
        $this->assertFileExists($path);

        return Yaml::parseFile($path);
    }

    public function test_contract_is_openapi_31_with_ten_unique_authenticated_operations(): void
    {
        $document = $this->document();
        $ids = [];
        foreach ($document['paths'] as $operations) {
            foreach ($operations as $operation) {
                $ids[] = $operation['operationId'];
            }
        }

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(8, $document['paths']);
        $this->assertCount(10, $ids);
        $this->assertCount(10, array_unique($ids));
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
        $this->assertGreaterThan(100, count($references));
    }

    public function test_mutation_and_domain_object_schemas_are_closed(): void
    {
        $schemas = $this->document()['components']['schemas'];
        foreach ([
            'CreateSleepPlanRequest', 'UpdateSleepPlanRequest', 'UpsertSleepLogRequest',
            'RoutineActivityInput', 'ReplaceRoutineActivitiesRequest',
            'UpsertRoutineActivityLogRequest', 'ReplaceRoutineDaySelectionsRequest',
            'SleepLog', 'SleepNight', 'SleepPlan', 'SleepStatistics', 'SleepWorkspace',
            'ActivityLog', 'RoutineActivity', 'RoutineTemplate', 'RoutineCandidate',
            'PeriodProjection', 'RoutineActivitySummary', 'RoutineTemplateActivitySummary',
            'RoutineDayProjection',
        ] as $name) {
            $this->assertFalse($schemas[$name]['additionalProperties'], "{$name} must be closed.");
        }
    }

    public function test_documented_and_registered_feature_operations_match_exactly(): void
    {
        $documented = [];
        foreach ($this->document()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        $patterns = [
            '#^api/sleep(?:/|$)#',
            '#^api/routine-selections/#',
            '#^api/routines/\{routine\}/activities(?:/|$)#',
        ];
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (! collect($patterns)->contains(fn (string $pattern): bool => preg_match($pattern, $route->uri()) === 1)) {
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
}
