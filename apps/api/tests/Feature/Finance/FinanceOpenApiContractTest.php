<?php

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class FinanceOpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function document(): array
    {
        $path = base_path('../../specs/018-finance-ledger-foundation/contracts/openapi.yaml');
        $this->assertFileExists($path);

        return Yaml::parseFile($path);
    }

    public function test_contract_has_fifteen_unique_authenticated_operations_and_resolving_refs(): void
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
        $this->assertCount(11, $document['paths']);
        $this->assertCount(15, $operations);
        $this->assertCount(15, array_unique($operations));
        $this->assertSame([['sanctum' => []]], $document['security']);
        foreach ($references as $reference) {
            $node = $document;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $this->assertArrayHasKey($segment, $node, $reference);
                $node = $node[$segment];
            }
        }
    }

    public function test_every_object_schema_is_closed_and_finance_routes_match_registration(): void
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
            if (! str_starts_with($route->uri(), 'api/finance/')) {
                continue;
            }
            $operation = strtoupper($route->methods()[0]).' /'.preg_replace_callback(
                '/\{[^}]+\}/',
                fn (array $match): string => match (true) {
                    str_contains($match[0], 'account') => '{account}',
                    str_contains($match[0], 'category') => '{category}',
                    str_contains($match[0], 'transaction') => '{transaction}',
                    default => $match[0],
                },
                preg_replace('#^api/#', '', $route->uri()),
            );
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            if (in_array($operation, $documented, true)) {
                $registered[] = $operation;
            }
        }

        sort($documented);
        sort($registered);
        $this->assertSame($documented, $registered);
    }

    public function test_account_reservations_and_transaction_sources_are_additive_contract_members(): void
    {
        $schemas = $this->document()['components']['schemas'];

        foreach (['reserved_amount', 'available_balance', 'over_reserved'] as $member) {
            $this->assertContains($member, $schemas['FinanceAccount']['required']);
            $this->assertArrayHasKey($member, $schemas['FinanceAccount']['properties']);
        }
        $this->assertContains('source', $schemas['TransactionGroup']['required']);
        $this->assertSame(
            ['purchase_item', 'supplement_restock_proposal'],
            $schemas['TransactionSourceContext']['properties']['type']['enum'],
        );
        foreach (['type', 'id', 'label', 'action_url', 'active'] as $member) {
            $this->assertContains($member, $schemas['TransactionSourceContext']['required']);
        }
    }
}
