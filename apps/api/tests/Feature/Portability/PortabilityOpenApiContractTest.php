<?php

namespace Tests\Feature\Portability;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class PortabilityOpenApiContractTest extends TestCase
{
    /** @return array<string,mixed> */
    private function document(): array
    {
        return Yaml::parseFile(base_path('../../specs/024-data-portability-reports/contracts/openapi.yaml'));
    }

    public function test_contract_has_five_unique_authenticated_operations_and_resolving_refs(): void
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
                if (! in_array($method, ['get', 'post'], true)) {
                    continue;
                }
                $operations[] = $operation['operationId'];
                $this->assertSame([['sanctum' => []]], $operation['security']);
            }
        }

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(5, $document['paths']);
        $this->assertCount(5, $operations);
        $this->assertCount(5, array_unique($operations));
        foreach ($references as $reference) {
            $node = $document;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $this->assertArrayHasKey($segment, $node, $reference);
                $node = $node[$segment];
            }
        }
    }

    public function test_closed_shapes_content_types_and_registered_routes_match(): void
    {
        $document = $this->document();
        foreach ($document['components']['schemas'] as $name => $schema) {
            if (($schema['type'] ?? null) === 'object') {
                $this->assertFalse($schema['additionalProperties'], $name);
            }
        }
        $this->assertArrayHasKey('text/csv', $document['paths']['/api/reports/analytics.csv']['get']['responses']['200']['content']);
        $this->assertArrayHasKey('application/pdf', $document['paths']['/api/reports/analytics.pdf']['get']['responses']['200']['content']);
        $this->assertArrayHasKey('application/zip', $document['paths']['/api/portability/backup']['get']['responses']['200']['content']);
        foreach (['/api/portability/restore/validate', '/api/portability/restore'] as $path) {
            $this->assertArrayHasKey('multipart/form-data', $document['paths'][$path]['post']['requestBody']['content']);
        }

        $documented = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'post'], true)) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (! preg_match('#^api/(reports/analytics\.(csv|pdf)|portability/)#', $route->uri())) {
                continue;
            }
            $registered[] = strtoupper($route->methods()[0]).' /'.$route->uri();
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
        sort($documented);
        sort($registered);
        $this->assertSame($documented, $registered);
    }
}
