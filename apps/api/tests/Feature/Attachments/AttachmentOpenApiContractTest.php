<?php

namespace Tests\Feature\Attachments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AttachmentOpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function document(): array
    {
        return Yaml::parseFile(base_path('../../specs/021-private-attachments/contracts/openapi.yaml'));
    }

    public function test_contract_has_three_unique_authenticated_operations_and_resolving_refs(): void
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
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $operations[] = $operation['operationId'];
                }
            }
        }

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(3, $document['paths']);
        $this->assertCount(3, $operations);
        $this->assertCount(3, array_unique($operations));
        $this->assertSame([['sanctum' => []]], $document['security']);
        foreach ($references as $reference) {
            $node = $document;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $this->assertArrayHasKey($segment, $node, $reference);
                $node = $node[$segment];
            }
        }
    }

    public function test_json_objects_are_closed_and_registered_attachment_routes_match(): void
    {
        $document = $this->document();
        foreach ($document['components']['schemas'] as $name => $schema) {
            if (($schema['type'] ?? null) === 'object' && $name !== 'ValidationErrorEnvelope') {
                $this->assertFalse($schema['additionalProperties'], $name);
            }
        }
        $documented = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'post', 'delete'], true)) {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/attachments')) {
                continue;
            }
            $registered[] = strtoupper($route->methods()[0]).' /'.preg_replace('#^api/#', '', $route->uri());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
        sort($documented);
        sort($registered);
        $this->assertSame($documented, $registered);
    }
}
