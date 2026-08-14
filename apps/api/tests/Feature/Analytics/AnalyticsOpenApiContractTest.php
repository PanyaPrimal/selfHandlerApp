<?php

namespace Tests\Feature\Analytics;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AnalyticsOpenApiContractTest extends TestCase
{
    private array $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->document = Yaml::parseFile(base_path('../../specs/023-analytics-long-period-rollups/contracts/openapi.yaml'));
    }

    public function test_contract_has_three_unique_authenticated_get_operations(): void
    {
        $this->assertSame('3.1.0', $this->document['openapi']);
        $this->assertCount(3, $this->document['paths']);
        $ids = [];
        foreach ($this->document['paths'] as $path => $operations) {
            $this->assertStringStartsWith('/analytics/', $path);
            $this->assertSame(['get'], array_keys($operations));
            $ids[] = $operations['get']['operationId'];
        }
        $this->assertCount(3, array_unique($ids));
        $this->assertSame([['sanctum' => []]], $this->document['security']);
    }

    public function test_every_local_reference_resolves_and_all_object_schemas_are_closed(): void
    {
        $walk = function (mixed $node) use (&$walk): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref'])) {
                $target = $this->document;
                foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                    $this->assertArrayHasKey($segment, $target, $node['$ref']);
                    $target = $target[$segment];
                }
            }
            if (($node['type'] ?? null) === 'object') {
                $this->assertArrayHasKey('additionalProperties', $node);
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($this->document);
    }
}
