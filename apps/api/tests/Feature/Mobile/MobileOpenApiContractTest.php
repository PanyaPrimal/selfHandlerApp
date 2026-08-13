<?php

namespace Tests\Feature\Mobile;

use App\Models\InAppNotification;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class MobileOpenApiContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        $path = base_path('../../specs/012-android-capacitor-shell/contracts/openapi.yaml');
        $this->assertFileExists($path);

        return Yaml::parseFile($path);
    }

    public function test_documented_operations_and_mobile_routes_match(): void
    {
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/mobile')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $registered[] = strtoupper($method).' /'.$route->uri();
                }
            }
        }

        $documented = [];
        foreach ($this->document()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        sort($registered);
        sort($documented);
        $this->assertSame($documented, $registered);
    }

    public function test_documented_security_and_channel_vocabulary_match_the_application(): void
    {
        $document = $this->document();
        $ack = $document['components']['schemas']['PresentationAcknowledgment'];

        $this->assertSame('mobileBearer', array_key_first(
            $document['paths']['/api/mobile/session']['get']['security'][0],
        ));
        $this->assertSame('android_local', $ack['properties']['channels']['contains']['const']);
        $this->assertContains(InAppNotification::CHANNEL_ANDROID_LOCAL, $ack['properties']['channels']['items']['enum']);
        $this->assertSame('sent', $ack['properties']['status']['const']);
    }
}
