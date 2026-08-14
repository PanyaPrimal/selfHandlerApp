<?php

namespace Tests\Feature\Review;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ReviewOpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,mixed> */
    private function document(): array
    {
        return Yaml::parseFile(base_path('../../specs/022-cross-module-periodic-review/contracts/openapi.yaml'));
    }

    public function test_contract_parses_has_unique_operations_and_all_local_refs_resolve(): void
    {
        $document = $this->document();
        $operations = [];
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
        foreach ($document['paths'] as $methods) {
            foreach ($methods as $method => $operation) {
                if (in_array($method, ['get', 'put'], true)) {
                    $operations[] = $operation['operationId'];
                }
            }
        }

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame([['sanctum' => []]], $document['security']);
        $this->assertCount(3, $operations);
        $this->assertCount(3, array_unique($operations));
        foreach ($references as $reference) {
            $this->assertStringStartsWith('#/', $reference);
            $node = $document;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $this->assertArrayHasKey($segment, $node, $reference);
                $node = $node[$segment];
            }
        }
    }

    public function test_public_objects_are_closed_and_routes_match_authenticated_contract(): void
    {
        $document = $this->document();
        foreach ($document['components']['schemas'] as $name => $schema) {
            if (($schema['type'] ?? null) === 'object') {
                $this->assertArrayHasKey('additionalProperties', $schema, $name);
                $this->assertFalse($schema['additionalProperties'], $name);
            }
        }

        $documented = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'put'], true)) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/review-workspaces')
                && ! str_starts_with($route->uri(), 'api/periodic-reviews')) {
                continue;
            }
            $registered[] = strtoupper($route->methods()[0]).' /'.preg_replace('#^api/#', '', $route->uri());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
        sort($documented);
        sort($registered);
        $this->assertSame($documented, $registered);
    }

    public function test_day_score_and_periodic_payload_are_closed_bounded_contracts(): void
    {
        $schemas = $this->document()['components']['schemas'];

        $this->assertSame(5, $schemas['DayScore']['properties']['total_components']['const']);
        $this->assertSame(
            ['nutrition', 'workouts', 'supplements', 'habits', 'planner'],
            $schemas['DayScoreComponent']['properties']['key']['enum'],
        );
        $this->assertSame(['weekly', 'monthly'], $schemas['PeriodicReviewType']['enum']);
        $this->assertSame(1, $schemas['PeriodicReviewPayload']['minProperties']);
        $this->assertSame(10000, $schemas['PeriodicReviewPayload']['properties']['notes']['maxLength']);
    }
}
