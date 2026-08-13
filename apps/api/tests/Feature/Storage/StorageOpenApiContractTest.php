<?php

namespace Tests\Feature\Storage;

use App\Models\Item;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The published contract has to describe the application that actually exists.
 *
 * A document nobody checks drifts, so this reads the OpenAPI file and holds it
 * against the route table and the vocabularies it claims to describe.
 */
class StorageOpenApiContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $path = base_path('../../specs/008-storage-inbox/contracts/openapi.yaml');

        $this->assertFileExists($path, 'The Storage OpenAPI contract is missing.');

        return Yaml::parseFile($path);
    }

    /**
     * @return list<string>
     */
    private function registeredOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $operations[] = strtoupper($method).' /'.ltrim(
                    (string) preg_replace('#^api/?#', '', $route->uri()),
                    '/',
                );
            }
        }

        return $operations;
    }

    public function test_every_documented_operation_is_a_real_route(): void
    {
        $registered = $this->registeredOperations();
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

    public function test_every_storage_route_is_documented(): void
    {
        $documented = array_keys($this->document()['paths']);

        foreach (Route::getRoutes() as $route) {
            // Only the API surface. Laravel also registers a framework route at
            //  for the local disk, which is not ours to document.
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $uri = '/'.ltrim(substr($route->uri(), 4), '/');

            if (! str_starts_with($uri, '/storage')) {
                continue;
            }

            $this->assertContains($uri, $documented, "The route {$uri} exists but is not in the contract.");
        }
    }

    public function test_the_documented_vocabularies_match_the_application(): void
    {
        $schemas = $this->document()['components']['schemas'];

        $this->assertSame(Item::TYPES, $schemas['ItemType']['enum'], 'The documented types have drifted.');
        $this->assertSame(Item::STATUSES, $schemas['ItemStatus']['enum'], 'The documented statuses have drifted.');
        $this->assertSame(Item::PRIORITIES, $schemas['ItemPriority']['enum'], 'The documented priorities have drifted.');
        $this->assertContains(Item::TYPE_PURCHASE, $schemas['ItemType']['enum']);
        foreach (['estimated_amount', 'estimated_currency_code'] as $member) {
            $this->assertContains($member, $schemas['Item']['required']);
            $this->assertArrayHasKey($member, $schemas['Item']['properties']);
            $this->assertArrayHasKey($member, $schemas['ItemInput']['properties']);
        }
    }
}
