<?php

namespace Tests\Feature\Review;

use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PeriodicReviewConcurrencyTest extends TestCase
{
    public function test_concurrent_alias_retries_commit_one_review_and_preserve_first_completion(): void
    {
        $root = sys_get_temp_dir().'/selfhandler-review-race-'.bin2hex(random_bytes(6));
        $barrier = $root.'/barrier';
        $database = $root.'/race.sqlite';
        mkdir($barrier, 0700, true);

        try {
            $this->seedRaceDatabase($database);
            $processes = [
                $this->worker($database, $barrier, 2, 1, 'First payload', '2026-08-14 10:00:00 UTC'),
                $this->worker($database, $barrier, 2, 2, 'Second payload', '2026-08-14 11:00:00 UTC'),
            ];
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }

            $pdo = new PDO('sqlite:'.$database);
            $row = $pdo->query('SELECT id, notes, completed_at FROM periodic_reviews')->fetch(PDO::FETCH_ASSOC);
            $this->assertIsArray($row);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM periodic_reviews')->fetchColumn());
            $this->assertContains($row['notes'], ['First payload', 'Second payload']);
            $firstCompletion = $row['completed_at'];

            $final = $this->worker(
                $database, $barrier, 1, 3, 'Final valid payload', '2026-08-14 12:00:00 UTC',
            );
            $final->run();
            $this->assertTrue($final->isSuccessful(), $final->getErrorOutput());
            $row = $pdo->query('SELECT notes, completed_at FROM periodic_reviews')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('Final valid payload', $row['notes']);
            $this->assertSame($firstCompletion, $row['completed_at']);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM periodic_reviews')->fetchColumn());
            unset($pdo);
        } finally {
            $this->removeTree($root);
        }
    }

    private function seedRaceDatabase(string $database): void
    {
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE periodic_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, period_type TEXT NOT NULL, period_start TEXT NOT NULL, period_end TEXT NOT NULL, period_rating INTEGER, worked_well TEXT, did_not_work TEXT, learned TEXT, next_focus TEXT, notes TEXT, completed_at TEXT NOT NULL, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE UNIQUE INDEX periodic_reviews_owner_period_uq ON periodic_reviews (user_id, period_type, period_start)');
        $pdo->exec("INSERT INTO users VALUES (1, 'Race', 'race@example.test', 'x', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        unset($pdo);
    }

    private function worker(
        string $database,
        string $barrier,
        int $expectedWorkers,
        int $worker,
        string $notes,
        string $now,
    ): Process {
        return new Process([
            PHP_BINARY,
            base_path('tests/Support/PeriodicReviewRaceWorker.php'),
            $database,
            $barrier,
            (string) $expectedWorkers,
            (string) $worker,
            $notes,
            $now,
        ], base_path(), timeout: 30);
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
