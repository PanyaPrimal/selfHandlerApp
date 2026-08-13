<?php

namespace Tests\Feature\Body;

use App\Models\BodyGoalDetail;
use App\ValueObjects\BodyMetric;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The published contract has to describe the application that actually exists.
 *
 * A contract document nobody checks drifts silently, so this reads
 * `specs/007-body-measurements/contracts/openapi.yaml` and holds it against the
 * route table and the enums it claims to describe.
 */
class BodyOpenApiContractTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $path = base_path('../../specs/007-body-measurements/contracts/openapi.yaml');

        $this->assertFileExists($path, 'The body OpenAPI contract is missing.');

        return Yaml::parseFile($path);
    }

    public function test_every_documented_operation_is_a_real_route(): void
    {
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                // Documented paths are relative to the /api server prefix.
                $registered[] = strtoupper($method).' /'.ltrim(
                    (string) preg_replace('#^api/?#', '', $route->uri()),
                    '/',
                );
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

    public function test_every_body_route_is_documented(): void
    {
        $documented = array_keys($this->document()['paths']);

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim((string) preg_replace('#^api/?#', '', $route->uri()), '/');

            if (! str_starts_with($uri, '/body')) {
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

        $this->assertSame(
            BodyMetric::values(),
            $document['components']['schemas']['BodyMetric']['enum'],
            'The documented metric vocabulary has drifted from BodyMetric.',
        );

        $this->assertSame(
            BodyGoalDetail::DIRECTIONS,
            $document['components']['schemas']['BodyGoalsResponse']['properties']['directions']['items']['enum'],
            'The documented direction vocabulary has drifted from BodyGoalDetail.',
        );
    }

    public function test_body_measurements_document_private_attachment_summaries_additively(): void
    {
        $schemas = $this->document()['components']['schemas'];

        $this->assertContains('attachments', $schemas['BodyMeasurement']['required']);
        $this->assertSame(
            '#/components/schemas/AttachmentSummary',
            $schemas['BodyMeasurement']['properties']['attachments']['items']['$ref'],
        );
        $this->assertFalse($schemas['AttachmentSummary']['additionalProperties']);
        $this->assertArrayNotHasKey('path', $schemas['AttachmentSummary']['properties']);
        $this->assertArrayNotHasKey('disk', $schemas['AttachmentSummary']['properties']);
    }
}
