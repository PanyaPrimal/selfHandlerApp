<?php

namespace Tests\Feature\Planner;

use App\Models\Item;
use App\Services\Planner\SourceRegistry;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The published contract has to describe the application that actually exists.
 *
 * A contract document nobody checks drifts silently, so this reads
 * `specs/009-planner-day/contracts/openapi.yaml` and holds it against the route
 * table and the vocabularies it claims to describe.
 */
class PlannerOpenApiContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $path = base_path('../../specs/009-planner-day/contracts/openapi.yaml');

        $this->assertFileExists($path, 'The planner OpenAPI contract is missing.');

        return Yaml::parseFile($path);
    }

    /**
     * @return list<string>
     */
    private function apiRoutes(): array
    {
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // Framework routes such as `storage/{path}` are not part of the API.
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $paths[] = '/'.ltrim((string) preg_replace('#^api/?#', '', $uri), '/');
        }

        return $paths;
    }

    public function test_every_documented_operation_is_a_real_route(): void
    {
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $path = '/'.ltrim((string) preg_replace('#^api/?#', '', $route->uri()), '/');

            foreach ($route->methods() as $method) {
                $registered[] = strtoupper($method).' '.$path;
            }
        }

        $documented = [];

        foreach ($this->document()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        $this->assertNotEmpty($documented);

        foreach ($documented as $operation) {
            $this->assertContains(
                $operation,
                $registered,
                "The contract documents {$operation}, which is not a registered route.",
            );
        }
    }

    public function test_every_planner_route_is_documented(): void
    {
        $documented = array_keys($this->document()['paths']);

        foreach ($this->apiRoutes() as $uri) {
            if (! str_starts_with($uri, '/planner')) {
                continue;
            }

            $this->assertContains(
                $uri,
                $documented,
                "The route {$uri} exists but is not in the contract.",
            );
        }
    }

    public function test_the_documented_vocabularies_match_the_application(): void
    {
        $document = $this->document();

        // A new source that nobody documents would appear on every day without
        // any client knowing what it is.
        $this->assertSame(
            app(SourceRegistry::class)->names(),
            $document['components']['schemas']['PlannerSource']['enum'],
            'The documented sources have drifted from the registry.',
        );

        $financeMeta = $document['components']['schemas']['PlannerEntry']['properties']['meta']['properties'];
        $this->assertSame(['recurring_operation', 'debt', 'fund'], $financeMeta['kind']['enum']);
        $this->assertSame(['income', 'expense', 'allocation'], $financeMeta['direction']['enum']);
        foreach (['owner_id', 'amount', 'currency', 'mandatory', 'occurrence_date', 'action_url'] as $member) {
            $this->assertArrayHasKey($member, $financeMeta);
        }
    }

    public function test_the_contract_does_not_claim_ownership_of_another_module(): void
    {
        $paths = array_keys($this->document()['paths']);

        foreach ($paths as $path) {
            $this->assertStringStartsWith(
                '/planner',
                $path,
                "The planner contract documents {$path}, which belongs to another module.",
            );
        }

        // Storage still owns a task's date, and the contract must not restate it.
        $this->assertNotContains('/storage/items/{item}', $paths);
        $this->assertContains(Item::STATUS_ACTIVE, Item::OPEN_STATUSES);
    }
}
