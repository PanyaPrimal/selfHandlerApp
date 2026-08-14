<?php

use App\Models\User;
use App\Services\Ai\InboxTriageProposalService;
use AppExceptions\AiAssistantException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$database, $barrier, $worker, $token, $appKey] = array_slice($argv, 1);
putenv('APP_ENV=testing');
putenv("APP_KEY={$appKey}");
putenv('DB_CONNECTION=sqlite');
putenv("DB_DATABASE={$database}");

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $database,
    'database.connections.sqlite.foreign_key_constraints' => true,
    'database.connections.sqlite.options' => [PDO::ATTR_TIMEOUT => 15],
]);
DB::purge('sqlite');
DB::statement('PRAGMA journal_mode=WAL');
DB::statement('PRAGMA busy_timeout=15000');

file_put_contents($barrier.'/ready-'.$worker, 'ready');
$deadline = microtime(true) + 15;
while (count(glob($barrier.'/ready-*') ?: []) < 2 && microtime(true) < $deadline) {
    usleep(10_000);
}
if (count(glob($barrier.'/ready-*') ?: []) < 2) {
    throw new RuntimeException('AI confirmation race barrier timed out.');
}

try {
    $item = $app->make(InboxTriageProposalService::class)->confirm(User::query()->findOrFail(1), $token);
    echo json_encode(['outcome' => 'applied', 'item_id' => $item->id], JSON_THROW_ON_ERROR);
} catch (AiAssistantException $exception) {
    echo json_encode(['outcome' => $exception->errorCode], JSON_THROW_ON_ERROR);
}
