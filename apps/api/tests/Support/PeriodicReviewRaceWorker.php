<?php

use App\Models\User;
use App\Services\Review\PeriodicReviewWriter;
use App\Support\ReviewPeriod;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$database, $barrier, $expectedWorkers, $worker, $notes, $now] = array_slice($argv, 1);
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv("DB_DATABASE={$database}");

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $database,
    'cache.default' => 'array',
]);
DB::purge('sqlite');
DB::connection('sqlite')->statement('PRAGMA busy_timeout = 5000');
Carbon::setTestNow($now);

file_put_contents($barrier.'/ready-'.$worker, 'ready');
$deadline = microtime(true) + 10;
while (count(glob($barrier.'/ready-*') ?: []) < (int) $expectedWorkers) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Periodic review race barrier timed out.');
    }
    usleep(10_000);
}

$review = $app->make(PeriodicReviewWriter::class)->upsert(
    User::query()->findOrFail(1),
    new ReviewPeriod('weekly', '2026-08-12', '2026-08-10', '2026-08-16', 'UTC'),
    ['notes' => $notes],
);

echo json_encode([
    'id' => $review->id,
    'notes' => $review->notes,
    'completed_at' => $review->completed_at?->toISOString(),
], JSON_THROW_ON_ERROR);
