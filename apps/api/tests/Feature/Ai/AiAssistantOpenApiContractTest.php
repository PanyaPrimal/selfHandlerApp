<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AiAssistantOpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_contract_operations_are_registered_unique_and_authenticated(): void
    {
        $document = Yaml::parseFile(base_path('../../specs/026-ai-assistant-foundation/contracts/openapi.yaml'));
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/'))
            ->flatMap(fn ($route) => collect($route->methods())->map(
                fn (string $method): string => strtoupper($method).' /'.preg_replace('#^api/?#', '', $route->uri()),
            ))->all();
        $operations = [];
        foreach ($document['paths'] as $path => $methods) {
            $path = preg_replace('/\{connection\}/', '{connection}', $path);
            foreach ($methods as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }
                $this->assertContains(strtoupper($method).' '.$path, $registered);
                $operations[] = $operation['operationId'];
            }
        }
        $this->assertSame($operations, array_values(array_unique($operations)));
        $this->assertSame([['sanctum' => []]], $document['security']);
    }

    public function test_contract_refs_resolve_and_response_schemas_never_expose_secret_fields(): void
    {
        $document = Yaml::parseFile(base_path('../../specs/026-ai-assistant-foundation/contracts/openapi.yaml'));
        $encoded = json_encode($document);
        preg_match_all('/#\/components\/(schemas|responses|parameters)\/([A-Za-z0-9_-]+)/', $encoded, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $this->assertArrayHasKey($match[2], $document['components'][$match[1]]);
        }

        $this->assertTrue($document['components']['schemas']['AiConnectionInput']['properties']['api_key']['writeOnly']);
        $response = json_encode($document['components']['schemas']['AiConnection']);
        foreach (['api_key', 'confirmation_token', 'proposal_hash', 'token_hash'] as $secret) {
            $this->assertStringNotContainsString('"'.$secret.'"', $response);
        }
    }
}
