<?php

namespace Tests\Feature\Analytics;

use App\Contracts\AnalyticsMetricSource;
use App\Services\Analytics\AnalyticsCatalog;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AnalyticsArchitectureTest extends TestCase
{
    public function test_analytics_composition_imports_no_raw_source_model(): void
    {
        $files = glob(app_path('Services/Analytics').'/*.php');
        $files[] = app_path('Http/Controllers/AnalyticsController.php');
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            $contents = file_get_contents($file);
            preg_match_all('/use App\\\\Models\\\\([^;]+);/', $contents, $matches);
            $this->assertSame(array_values(array_filter($matches[1], fn (string $model): bool => $model !== 'User')), []);
        }
    }

    public function test_contract_and_catalog_are_closed_and_no_023_migration_exists(): void
    {
        $this->assertTrue(interface_exists(AnalyticsMetricSource::class));
        $this->assertCount(17, (new AnalyticsCatalog)->metrics());
        $this->assertSame([], glob(database_path('migrations/*023*analytics*')) ?: []);
    }

    public function test_exactly_three_authenticated_read_only_routes_exist(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(
            fn ($route): bool => str_starts_with($route->uri(), 'api/analytics'),
        );

        $this->assertCount(3, $routes);
        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }
}
