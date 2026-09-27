<?php

namespace Tests\Feature\Nutrition;

use App\Models\NutritionSettings;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class NutritionPortabilityTest extends NutritionTestCase
{
    public function test_manual_macros_round_trip_and_legacy_archives_restore_percentage_mode(): void
    {
        $owner = $this->createUser();
        $macros = ['protein' => 35, 'fat' => 70, 'carbs' => 210];
        NutritionSettings::create(['user_id' => $owner->id, 'macro_targets_grams' => $macros]);
        $response = $this->actingAs($owner)->get('/api/portability/backup')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $bytes = file_get_contents($path);
        @unlink($path);

        foreach ([false, true] as $legacy) {
            $backup = $legacy ? $this->rewriteBackup($bytes, function (array &$attributes): void {
                unset($attributes['macro_targets_grams']);
            }) : $bytes;
            $target = $this->createUser($legacy ? 'legacy@example.test' : 'restored@example.test');
            $upload = fn () => UploadedFile::fake()->createWithContent('backup.zip', $backup);
            $validated = $this->actingAs($target)->post('/api/portability/restore/validate', ['backup' => $upload()], ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.eligible', true);
            $this->post('/api/portability/restore', [
                'backup' => $upload(), 'restore_token' => $validated->json('data.restore_token'), 'confirmation' => 'RESTORE',
            ], ['Accept' => 'application/json'])->assertOk();
            $restored = NutritionSettings::query()->ownedBy($target)->firstOrFail();
            $this->assertEquals($legacy ? null : $macros, $restored->macro_targets_grams);
            $this->assertSame('20.00', $restored->protein_percent);
        }

        $empty = $this->createUser('invalid@example.test');
        foreach ([[], ['protein' => 35], ['protein' => 35, 'fat' => -1, 'carbs' => 210],
            ['protein' => 35, 'fat' => 70, 'carbs' => 1001], ['protein' => 35, 'fat' => 70, 'carbs' => 210, 'extra' => 0]] as $invalid) {
            $backup = $this->rewriteBackup($bytes, function (array &$attributes) use ($invalid): void {
                $attributes['macro_targets_grams'] = $invalid;
            });
            $this->actingAs($empty)->post('/api/portability/restore/validate', [
                'backup' => UploadedFile::fake()->createWithContent('backup.zip', $backup),
            ], ['Accept' => 'application/json'])->assertUnprocessable();
            $this->assertSame(0, NutritionSettings::query()->ownedBy($empty)->count());
        }
        $this->assertEquals($macros, NutritionSettings::query()->ownedBy($owner)->firstOrFail()->macro_targets_grams);
    }

    private function rewriteBackup(string $bytes, callable $change): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nutrition-backup-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $original = $zip->getFromName('data/records.json');
        $records = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        $change($records['tables']['nutrition_settings'][0]['attributes']);
        $replacement = json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['members'] as &$member) {
            if ($member['path'] === 'data/records.json') {
                $member['size_bytes'] = strlen($replacement);
                $member['sha256'] = hash('sha256', $replacement);
            }
        }
        unset($member);
        $manifest['counts']['total_bytes'] += strlen($replacement) - strlen($original);
        $zip->addFromString('data/records.json', $replacement);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();
        $result = file_get_contents($path);
        @unlink($path);

        return $result;
    }
}
