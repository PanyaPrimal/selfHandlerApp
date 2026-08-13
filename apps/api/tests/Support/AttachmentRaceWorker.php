<?php

use App\Exceptions\Attachments\AttachmentConflict;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\FileStorage;
use App\Services\Attachments\ImageNormalizer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

putenv('APP_ENV=testing');
putenv('APP_KEY=base64:8mx6/PHn6hHX2o4bOMOlPxpdrJeWHdxklSX7Z92ro8Q=');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$database, $storageRoot, $barrier, $worker, $parentId, $uploadKey, $maxOwnerBytes] = array_slice($argv, 1);
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $database,
    'database.connections.sqlite.foreign_key_constraints' => true,
    'database.connections.sqlite.options' => [PDO::ATTR_TIMEOUT => 15],
    'filesystems.disks.attachment_race' => [
        'driver' => 'local', 'root' => $storageRoot, 'throw' => false,
    ],
    'attachments.disk' => 'attachment_race',
    'attachments.max_source_bytes' => 5 * 1024 * 1024,
    'attachments.max_stored_bytes' => 5 * 1024 * 1024,
    'attachments.max_dimension' => 2560,
    'attachments.max_source_pixels' => 40_000_000,
    'attachments.max_per_parent' => 10,
    'attachments.max_bytes_per_user' => (int) $maxOwnerBytes,
]);
DB::purge('sqlite');
DB::statement('PRAGMA journal_mode=WAL');
DB::statement('PRAGMA busy_timeout=15000');
Storage::forgetDisk('attachment_race');

$storage = new class($barrier, $worker) extends FileStorage
{
    public function __construct(private readonly string $barrier, private readonly string $worker) {}

    public function put(string $path, string $sourcePath): void
    {
        file_put_contents($this->barrier.'/ready-'.$this->worker, 'ready');
        $deadline = microtime(true) + 15;
        while (count(glob($this->barrier.'/ready-*') ?: []) < 2 && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (count(glob($this->barrier.'/ready-*') ?: []) < 2) {
            throw new RuntimeException('Attachment race barrier timed out.');
        }
        parent::put($path, $sourcePath);
    }
};

$path = tempnam(sys_get_temp_dir(), 'attachment-race-image-');
$image = imagecreatetruecolor(120, 80);
$color = imagecolorallocate($image, 42, 92, 130);
imagefill($image, 0, 0, $color);
imagepng($image, $path);
imagedestroy($image);

try {
    $user = User::query()->findOrFail(1);
    $result = (new AttachmentService($storage, new ImageNormalizer))->upload(
        $user,
        'body_measurement',
        (int) $parentId,
        $uploadKey,
        new UploadedFile($path, 'race.png', 'image/png', null, true),
    );
    echo json_encode(['outcome' => 'created', 'id' => $result->attachment->id], JSON_THROW_ON_ERROR);
} catch (ValidationException) {
    echo json_encode(['outcome' => 'quota'], JSON_THROW_ON_ERROR);
} catch (AttachmentConflict) {
    echo json_encode(['outcome' => 'conflict'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'contended', 'class' => $exception::class], JSON_THROW_ON_ERROR);
} finally {
    @unlink($path);
}
