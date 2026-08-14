<?php

namespace Tests\Feature\Ai;

use App\Models\Item;
use App\Models\LlmConnection;
use App\Models\LlmConsent;
use App\Models\LlmSetting;
use App\Models\User;
use App\Services\Ai\LlmConfirmationTokenService;
use App\Services\Ai\LlmToolRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AiConfirmationConcurrencyTest extends TestCase
{
    public function test_two_simultaneous_confirmations_apply_the_storage_write_at_most_once(): void
    {
        $root = sys_get_temp_dir().'/selfhandler-ai-confirmation-race-'.bin2hex(random_bytes(6));
        $barrier = $root.'/barrier';
        $database = $root.'/race.sqlite';
        mkdir($barrier, 0700, true);
        touch($database);
        $originalDatabase = config('database.connections.sqlite.database');

        try {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
            DB::purge('sqlite');
            Artisan::call('migrate:fresh', ['--force' => true]);
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA busy_timeout=15000');

            $user = User::factory()->create(['id' => 1]);
            $connection = LlmConnection::query()->create([
                'user_id' => $user->id,
                'name' => 'Race connection',
                'provider' => LlmConnection::PROVIDER_OPENAI,
                'model' => 'fixture-model',
                'api_key' => 'fixture-provider-key-1234',
                'key_hint' => '1234',
                'parameters' => ['max_output_tokens' => 512],
                'status' => LlmConnection::STATUS_READY,
                'last_tested_at' => now(),
            ]);
            LlmSetting::query()->create(['user_id' => $user->id, 'active_connection_id' => $connection->id]);
            LlmConsent::query()->create([
                'user_id' => $user->id,
                'scope' => LlmConsent::SCOPE_STORAGE_INBOX,
                'granted_at' => now(),
            ]);
            $item = Item::query()->create(['user_id' => $user->id, 'title' => 'Apply once']);
            $issued = app(LlmConfirmationTokenService::class)->issue(
                $user,
                $connection,
                $item,
                LlmToolRegistry::STORAGE_TRIAGE_TOOL,
                [
                    'type' => 'task',
                    'project_id' => null,
                    'tags' => ['race-safe'],
                    'priority' => 'high',
                    'due_on' => '2026-08-20',
                    'rationale' => 'One bounded proposal.',
                ],
            );
            DB::disconnect('sqlite');

            $processes = [1, 2];
            $processes = array_map(fn (int $worker): Process => new Process([
                PHP_BINARY,
                base_path('tests/Support/AiConfirmationRaceWorker.php'),
                $database,
                $barrier,
                (string) $worker,
                $issued['token'],
                (string) config('app.key'),
            ], base_path(), timeout: 30), $processes);
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $outcomes = array_map(fn (Process $process): array => $this->workerOutcome($process), $processes);

            $pdo = new PDO('sqlite:'.$database);
            $this->assertSame(1, count(array_filter(
                $outcomes,
                static fn (array $outcome): bool => $outcome['outcome'] === 'applied',
            )));
            $this->assertSame(1, count(array_filter(
                $outcomes,
                static fn (array $outcome): bool => $outcome['outcome'] === 'ai_confirmation_replayed',
            )));
            $this->assertSame('active', $pdo->query('SELECT status FROM items WHERE id = 1')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM tags WHERE name = 'race-safe'")->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM item_tag')->fetchColumn());
            $this->assertSame('applied', $pdo->query('SELECT status FROM llm_tool_confirmations')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM llm_audit_events WHERE event = 'confirmation_applied'")->fetchColumn());
            unset($pdo);
        } finally {
            DB::disconnect('sqlite');
            config(['database.connections.sqlite.database' => $originalDatabase]);
            DB::purge('sqlite');
            $this->removeTree($root);
        }
    }

    /** @return array{outcome:string,item_id?:int} */
    private function workerOutcome(Process $process): array
    {
        $output = trim($process->getOutput());
        if (str_contains($output, 'ai_confirmation_replayed')) {
            return ['outcome' => 'ai_confirmation_replayed'];
        }
        $json = strrchr($output, '{');
        $this->assertIsString($json, $process->getOutput());

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
