<?php

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;
use App\Models\NotificationSettings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class NotificationOpenApiContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        $path = base_path('../../specs/011-in-app-notifications/contracts/openapi.yaml');
        $this->assertFileExists($path);

        return Yaml::parseFile($path);
    }

    public function test_documented_operations_and_notification_routes_match(): void
    {
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/notifications')) {
                continue;
            }

            $path = '/'.preg_replace('#^api/#', '', $route->uri());
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $registered[] = strtoupper($method).' '.$path;
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

    public function test_documented_vocabularies_match_the_models(): void
    {
        $schemas = $this->document()['components']['schemas'];

        $this->assertSame(InAppNotification::VISIBLE_STATUSES, $schemas['NotificationStatus']['enum']);
        $this->assertSame(InAppNotification::CATEGORIES, $schemas['NotificationCategory']['enum']);
        $this->assertSame(InAppNotification::TYPES, $schemas['NotificationType']['enum']);
        $this->assertSame(NotificationSettings::SNOOZE_MINUTES, $schemas['NotificationListResponse']['properties']['snooze_options']['const']);
    }
}
