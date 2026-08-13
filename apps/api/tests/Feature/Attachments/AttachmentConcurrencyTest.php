<?php

namespace Tests\Feature\Attachments;

use App\Services\Attachments\ImageNormalizer;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\Support\AttachmentTestCase;

class AttachmentConcurrencyTest extends AttachmentTestCase
{
    public function test_two_processes_cannot_commit_beyond_final_parent_slot(): void
    {
        $environment = $this->raceEnvironment('parent');
        try {
            $this->seedRaceDatabase($environment['database'], parentCount: 9);
            $outcomes = $this->race($environment, [1, 1], 100 * 1024 * 1024);
            $pdo = new PDO('sqlite:'.$environment['database']);
            $attachmentCount = (int) $pdo->query('select count(*) from attachments')->fetchColumn();
            unset($pdo);

            $this->assertSame(10, $attachmentCount);
            $this->assertSame(1, count(array_filter($outcomes, fn (array $result): bool => $result['outcome'] === 'created')));
            $this->assertSame(1, $this->fileCount($environment['storage']));
        } finally {
            $this->removeTree($environment['root']);
        }
    }

    public function test_two_processes_cannot_commit_beyond_final_owner_byte(): void
    {
        $environment = $this->raceEnvironment('owner');
        $probeUpload = $this->image();
        $probe = app(ImageNormalizer::class)->normalize($probeUpload);
        $incomingBytes = $probe->sizeBytes;
        $probe->release();
        @unlink($probeUpload->getPathname());
        $maximum = 100 * 1024 * 1024;

        try {
            $this->seedRaceDatabase($environment['database'], ownerBytes: $maximum - $incomingBytes);
            $outcomes = $this->race($environment, [2, 3], $maximum);
            $pdo = new PDO('sqlite:'.$environment['database']);
            $ownerBytes = (int) $pdo->query('select sum(size_bytes) from attachments')->fetchColumn();
            unset($pdo);

            $this->assertSame($maximum, $ownerBytes);
            $this->assertSame(1, count(array_filter($outcomes, fn (array $result): bool => $result['outcome'] === 'created')));
            $this->assertSame(1, $this->fileCount($environment['storage']));
        } finally {
            $this->removeTree($environment['root']);
        }
    }

    /** @return array{root: string, database: string, storage: string, barrier: string} */
    private function raceEnvironment(string $name): array
    {
        $root = sys_get_temp_dir().'/selfhandler-attachment-race-'.$name.'-'.bin2hex(random_bytes(6));
        $storage = $root.'/storage';
        $barrier = $root.'/barrier';
        mkdir($storage, 0700, true);
        mkdir($barrier, 0700, true);

        return ['root' => $root, 'database' => $root.'/race.sqlite', 'storage' => $storage, 'barrier' => $barrier];
    }

    private function seedRaceDatabase(string $database, int $parentCount = 0, int $ownerBytes = 0): void
    {
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE body_measurements (id INTEGER PRIMARY KEY, user_id INTEGER, metric TEXT, measured_on TEXT, value NUMERIC, note TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, attachable_type TEXT, attachable_id INTEGER, disk TEXT, path TEXT, original_name TEXT, mime_type TEXT, size_bytes INTEGER, kind TEXT, width INTEGER, height INTEGER, sha256 TEXT, upload_key TEXT, meta TEXT, created_at TEXT)');
        $pdo->exec('CREATE UNIQUE INDEX attachments_owner_upload_uq ON attachments (user_id, upload_key)');
        $pdo->exec('CREATE UNIQUE INDEX attachments_disk_path_uq ON attachments (disk, path)');
        $pdo->exec("INSERT INTO users VALUES (1, 'race@example.test', 'x', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        foreach (range(1, 3) as $id) {
            $pdo->exec("INSERT INTO body_measurements VALUES ({$id}, 1, 'body_mass', '2026-08-14', 70000, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        }
        for ($index = 1; $index <= $parentCount; $index++) {
            $this->insertSeedAttachment($pdo, $index, 1, 1);
        }
        if ($ownerBytes > 0) {
            $this->insertSeedAttachment($pdo, 1, 1, $ownerBytes);
        }
    }

    private function insertSeedAttachment(PDO $pdo, int $index, int $parentId, int $bytes): void
    {
        $uuid = sprintf('00000000-0000-4000-8000-%012d', $index);
        $statement = $pdo->prepare('INSERT INTO attachments (user_id, attachable_type, attachable_id, disk, path, original_name, mime_type, size_bytes, kind, width, height, sha256, upload_key, meta, created_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, NULL, CURRENT_TIMESTAMP)');
        $statement->execute([
            'body_measurement', $parentId, 'attachment_race', "attachments/1/{$uuid}.png",
            "seed-{$index}.png", 'image/png', $bytes, 'photo', str_repeat('a', 64), "seed-{$index}",
        ]);
    }

    /** @param list<int> $parentIds @return list<array{outcome: string}> */
    private function race(array $environment, array $parentIds, int $maximumOwnerBytes): array
    {
        $processes = [];
        foreach ($parentIds as $index => $parentId) {
            $process = new Process([
                ...$this->phpCommand(), base_path('tests/Support/AttachmentRaceWorker.php'),
                $environment['database'], $environment['storage'], $environment['barrier'],
                (string) ($index + 1), (string) $parentId, 'race-'.($index + 1), (string) $maximumOwnerBytes,
            ], base_path(), timeout: 30);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        return array_map(static fn (Process $process): array => json_decode(
            $process->getOutput(), true, flags: JSON_THROW_ON_ERROR,
        ), $processes);
    }

    /** @return list<string> */
    private function phpCommand(): array
    {
        $command = [PHP_BINARY];
        if (PHP_OS_FAMILY !== 'Windows' || php_ini_loaded_file() !== false) {
            return $command;
        }
        $extensionDirectory = (string) ini_get('extension_dir');
        $command = [PHP_BINARY, '-n', '-d', "extension_dir={$extensionDirectory}"];
        foreach (['mbstring', 'pdo_sqlite', 'sqlite3', 'fileinfo', 'gd', 'openssl', 'exif'] as $extension) {
            $command[] = '-d';
            $command[] = "extension={$extensionDirectory}/php_{$extension}.dll";
        }

        return $command;
    }

    private function fileCount(string $directory): int
    {
        return count(array_filter(iterator_to_array(new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        )), static fn ($item): bool => $item->isFile()));
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
