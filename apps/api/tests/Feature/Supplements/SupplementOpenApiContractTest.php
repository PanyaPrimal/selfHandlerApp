<?php

namespace Tests\Feature\Supplements;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

class SupplementOpenApiContractTest extends SupplementTestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        $path = base_path('../../specs/017-supplements-courses-intake-stock/contracts/openapi.yaml');
        $this->assertFileExists($path);

        return Yaml::parseFile($path);
    }

    public function test_contract_has_thirteen_unique_authenticated_operations_and_resolving_refs(): void
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
        foreach ($document['paths'] as $path) {
            foreach ($path as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $operations[] = $operation['operationId'];
                }
            }
        }

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(9, $document['paths']);
        $this->assertCount(13, $operations);
        $this->assertCount(13, array_unique($operations));
        $this->assertSame([['bearerAuth' => []]], $document['security']);

        foreach ($references as $reference) {
            $node = $document;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $this->assertArrayHasKey($segment, $node, $reference);
                $node = $node[$segment];
            }
        }
    }

    public function test_every_object_schema_is_closed_and_documented_routes_match_registration(): void
    {
        $document = $this->document();
        foreach ($document['components']['schemas'] as $name => $schema) {
            if (($schema['type'] ?? null) === 'object') {
                $this->assertFalse($schema['additionalProperties'], $name);
            }
        }

        $documented = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (preg_match('#^api/(?:supplements|supplement-)#', $route->uri()) !== 1) {
                continue;
            }
            $registered[] = strtoupper($route->methods()[0]).' /'.preg_replace_callback(
                '/\{[^}]+\}/',
                fn (array $match): string => match (true) {
                    str_contains($match[0], 'supplement') => '{supplement}',
                    str_contains($match[0], 'course') => '{course}',
                    str_contains($match[0], 'occurrence') => '{occurrence}',
                    str_contains($match[0], 'proposal') => '{proposal}',
                    str_contains($match[0], 'date') => '{date}',
                    default => $match[0],
                },
                preg_replace('#^api/#', '', $route->uri()),
            );
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }

        sort($documented);
        sort($registered);
        $this->assertSame($documented, $registered);
    }
}
