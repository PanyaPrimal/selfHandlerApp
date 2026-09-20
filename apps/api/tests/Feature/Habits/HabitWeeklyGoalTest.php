<?php

namespace Tests\Feature\Habits;

use App\Models\Habit;
use App\Services\HabitAnalyticsSeriesService;
use App\Services\HabitPeriodSummaryService;
use App\Services\HabitStatisticsService;
use App\Services\Notifications\NotificationSourceSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

class HabitWeeklyGoalTest extends HabitTestCase
{
    public function test_numeric_goals_count_successful_dates_and_a_closed_incomplete_week_breaks_the_streak(): void
    {
        $owner = $this->createUser();
        $id = $this->actingAs($owner)->postJson('/api/habits', [
            'name' => 'Read', 'kind' => 'habit', 'mode' => 'numeric', 'target_value' => 10, 'unit' => 'pages',
            'schedule_type' => 'weekly_target', 'weekly_target' => 2,
        ])->assertCreated()->json('data.id');
        CarbonImmutable::setTestNow('2026-08-16 12:00:00 UTC');
        foreach (['2026-08-13' => 5, '2026-08-14' => 10, '2026-08-16' => 12] as $date => $value) {
            $this->putJson("/api/habits/{$id}/logs/{$date}", ['outcome' => 'recorded', 'value' => $value, 'occurred_time' => '08:00'])->assertOk();
        }
        $this->getJson('/api/habits')->assertJsonPath('data.0.weekly_progress.completed', 2)
            ->assertJsonPath('data.0.statistics.current_streak', 1)->assertJsonPath('data.0.statistics.numeric_total', 27);
        CarbonImmutable::setTestNow('2026-08-24 12:00:00 UTC');
        $this->getJson('/api/habits')->assertJsonPath('data.0.weekly_progress.completed', 0)
            ->assertJsonPath('data.0.statistics.current_streak', 0)->assertJsonPath('data.0.statistics.best_streak', 1);
    }

    public function test_switch_from_rescheduled_days_retains_fact_identity_and_allows_any_day(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, ['name' => 'River'], ['TH']);
        $row = $this->occurrenceOn($habit);
        $this->actingAs($owner)->patchJson("/api/planner/occurrences/{$row->id}/reschedule", [
            'rescheduled_to' => '2026-08-14',
        ])->assertOk();
        CarbonImmutable::setTestNow('2026-08-14 12:00:00 UTC');
        $fact = $this->createLog($habit, $owner, '2026-08-14', ['outcome' => 'done', 'occurred_time' => '08:00']);
        $this->patchJson("/api/habits/{$habit->id}", ['schedule_type' => 'weekly_target', 'weekly_target' => 3])
            ->assertOk()->assertJsonPath('data.selected_day.log.id', $fact->id)
            ->assertJsonPath('data.weekly_progress.completed', 1);
        $this->assertSame($fact->id, $row->fresh()->habit_log_id);
        $this->putJson("/api/habits/{$habit->id}/logs/2026-08-14", ['outcome' => 'done', 'occurred_time' => '09:00'])->assertOk();
        $this->putJson("/api/habits/{$habit->id}/logs/2026-08-13", ['outcome' => 'done', 'occurred_time' => '09:00'])->assertOk();
        $this->getJson('/api/today')->assertJsonPath('summary.done', 1)->assertJsonPath('summary.scheduled', 1)
            ->assertJsonPath('habits.0.weekly_progress.completed', 2);
        $this->assertDatabaseCount('habit_logs', 2);
    }

    public function test_current_week_backfill_is_available_without_fixed_weekdays(): void
    {
        $owner = $this->createUser();
        $id = $this->actingAs($owner)->postJson('/api/habits', [
            'name' => 'River', 'kind' => 'habit', 'mode' => 'yes_no',
            'schedule_type' => 'weekly_target', 'weekly_target' => 3,
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/habits/{$id}/logs/2026-08-11", ['outcome' => 'done', 'occurred_time' => '08:00'])->assertOk();
        $this->getJson('/api/today')->assertJsonPath('summary.done', 0)
            ->assertJsonPath('progress.seven_day.done', 1)
            ->assertJsonPath('progress.seven_day.scheduled', 1)
            ->assertJsonPath('habits.0.weekly_progress.completed', 1);
    }

    public function test_weekly_goals_and_facts_survive_portable_restore_and_old_backups_remain_readable(): void
    {
        $owner = $this->createUser();
        $id = $this->actingAs($owner)->postJson('/api/habits', [
            'name' => 'River', 'kind' => 'habit', 'mode' => 'yes_no',
            'schedule_type' => 'weekly_target', 'weekly_target' => 3,
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/habits/{$id}/logs/".self::TODAY, ['outcome' => 'done', 'occurred_time' => '08:00'])->assertOk();
        $response = $this->get('/api/portability/backup')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $bytes = file_get_contents($path);
        @unlink($path);
        foreach ([false, true] as $legacy) {
            $backup = $legacy ? $this->rewriteBackup($bytes, function (array &$attributes): void {
                unset($attributes['weekly_target'], $attributes['weekly_target_history']);
            }) : $bytes;
            $target = $this->createUser($legacy ? 'legacy@example.test' : 'restored@example.test');
            $upload = fn () => UploadedFile::fake()->createWithContent('backup.zip', $backup);
            $validated = $this->actingAs($target)->post('/api/portability/restore/validate', ['backup' => $upload()], ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.eligible', true);
            $this->post('/api/portability/restore', [
                'backup' => $upload(), 'restore_token' => $validated->json('data.restore_token'), 'confirmation' => 'RESTORE',
            ], ['Accept' => 'application/json'])->assertOk();
            $restored = Habit::query()->ownedBy($target)->firstOrFail();
            $this->assertSame($legacy ? null : 3, $restored->weekly_target);
            $this->assertSame(1, $restored->logs()->count());
            $this->getJson('/api/today')->assertOk()->assertJsonPath('summary.done', 1);
            if (! $legacy) {
                $this->assertSame(3, $restored->weeklyTargetForDate(self::TODAY));
            }
        }
        $invalid = $this->rewriteBackup($bytes, function (array &$attributes): void {
            $attributes['weekly_target_history'] = ['2026-08-10' => 99];
        });
        $empty = $this->createUser('invalid@example.test');
        $this->actingAs($empty)->post('/api/portability/restore/validate', [
            'backup' => UploadedFile::fake()->createWithContent('backup.zip', $invalid),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, Habit::query()->ownedBy($empty)->count());
    }

    private function rewriteBackup(string $bytes, callable $change): string
    {
        $path = tempnam(sys_get_temp_dir(), 'habit-backup-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $original = $zip->getFromName('data/records.json');
        $records = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        $change($records['tables']['habits'][0]['attributes']);
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

    public function test_switch_preserves_existing_fact_and_today_includes_habits_with_routines(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, ['name' => 'River'], ['TH']);
        $fact = $this->createLog($habit, $owner, self::TODAY, ['outcome' => 'done', 'occurred_time' => '08:00']);
        $occurrence = $this->occurrenceOn($habit);
        $this->createRoutine($owner);
        $this->actingAs($owner)->patchJson("/api/habits/{$habit->id}", [
            'schedule_type' => 'weekly_target', 'weekly_target' => 3,
        ])->assertOk()->assertJsonPath('data.schedule.schedule_type', 'weekly_target')
            ->assertJsonPath('data.schedule.weekdays', [])
            ->assertJsonPath('data.selected_day.log.id', $fact->id)
            ->assertJsonPath('data.weekly_progress.completed', 1)
            ->assertJsonPath('data.weekly_progress.target', 3);

        $this->assertSame($fact->id, $occurrence->fresh()->habit_log_id);
        $this->assertDatabaseCount('habit_logs', 1);
        $this->getJson('/api/today')->assertOk()
            ->assertJsonPath('summary.scheduled', 2)->assertJsonPath('summary.done', 1)
            ->assertJsonPath('summary.pending', 1)->assertJsonPath('summary.completion_rate', 50)
            ->assertJsonPath('habits.0.selected_day.log.successful', true)
            ->assertJsonPath('habits.0.weekly_progress.completed', 1);
    }

    public function test_any_three_days_complete_goal_and_corrections_do_not_duplicate_facts(): void
    {
        $owner = $this->createUser();
        $id = $this->actingAs($owner)->postJson('/api/habits', [
            'name' => 'River', 'kind' => 'habit', 'mode' => 'yes_no',
            'schedule_type' => 'weekly_target', 'weekly_target' => 3,
        ])->assertCreated()->json('data.id');
        CarbonImmutable::setTestNow('2026-08-16 12:00:00 UTC');
        foreach (['2026-08-13', '2026-08-14', '2026-08-16', '2026-08-16'] as $date) {
            $this->putJson("/api/habits/{$id}/logs/{$date}", [
                'outcome' => 'done', 'occurred_time' => '08:00',
            ])->assertOk();
        }
        $this->assertDatabaseCount('habit_logs', 3);
        $this->getJson('/api/habits')->assertOk()
            ->assertJsonPath('data.0.weekly_progress.completed', 3)
            ->assertJsonPath('data.0.weekly_progress.achieved', true)
            ->assertJsonPath('data.0.statistics.current_streak', 1)
            ->assertJsonPath('data.0.statistics.opportunities', 3)
            ->assertJsonPath('data.0.statistics.completion_percentage', 100);
        $this->deleteJson("/api/habits/{$id}/logs/2026-08-16")->assertNoContent();
        $this->getJson('/api/today')->assertOk()->assertJsonPath('summary.done', 0)
            ->assertJsonPath('summary.scheduled', 0)
            ->assertJsonPath('habits.0.weekly_progress.completed', 2)
            ->assertJsonPath('habits.0.weekly_progress.remaining', 1);
    }

    public function test_free_days_do_not_become_daily_obligations_or_reminders(): void
    {
        $owner = $this->createUser();
        $id = $this->actingAs($owner)->postJson('/api/habits', [
            'name' => 'River', 'kind' => 'habit', 'mode' => 'yes_no',
            'schedule_type' => 'weekly_target', 'weekly_target' => 3, 'preferred_time' => '08:00',
        ])->assertCreated()->json('data.id');
        $this->getJson('/api/today')->assertOk()->assertJsonPath('summary.scheduled', 0)
            ->assertJsonPath('habits.0.id', $id)->assertJsonPath('habits.0.weekly_progress.completed', 0);
        $this->getJson('/api/planner/day?date='.self::TODAY)->assertOk()->assertJsonCount(0, 'entries');
        $this->assertSame(0, app(NotificationSourceSynchronizer::class)->synchronize($owner, CarbonImmutable::now()));
        $this->assertSame(0, app(HabitPeriodSummaryService::class)->summarize($owner, self::TODAY, self::TODAY)['scheduled']);
        $this->assertSame([], app(HabitAnalyticsSeriesService::class)->daily($owner, self::TODAY, self::TODAY));
        $this->putJson("/api/habits/{$id}/logs/".self::TODAY, ['outcome' => 'done', 'occurred_time' => '08:00'])->assertOk();
        $this->getJson('/api/planner/day?date='.self::TODAY)->assertOk()->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.status', 'done');
    }

    public function test_week_rollover_and_goal_changes_keep_the_previous_week_target(): void
    {
        $owner = $this->createUser();
        $id = $this->actingAs($owner)->postJson('/api/habits', [
            'name' => 'River', 'kind' => 'habit', 'mode' => 'yes_no',
            'schedule_type' => 'weekly_target', 'weekly_target' => 3,
        ])->assertCreated()->json('data.id');
        CarbonImmutable::setTestNow('2026-08-17 12:00:00 UTC');
        foreach (['2026-08-13', '2026-08-14', '2026-08-16'] as $date) {
            $this->putJson("/api/habits/{$id}/logs/{$date}", ['outcome' => 'done', 'occurred_time' => '08:00'])->assertOk();
        }
        $this->getJson('/api/habits')->assertJsonPath('data.0.weekly_progress.from', '2026-08-17')
            ->assertJsonPath('data.0.weekly_progress.completed', 0)
            ->assertJsonPath('data.0.statistics.current_streak', 1);
        $this->patchJson("/api/habits/{$id}", ['weekly_target' => 4])->assertOk();
        $this->getJson('/api/habits?date=2026-08-16')->assertJsonPath('data.0.weekly_progress.target', 3)
            ->assertJsonPath('data.0.weekly_progress.achieved', true);
        $this->getJson('/api/habits')->assertJsonPath('data.0.weekly_progress.target', 4);
        $habit = Habit::findOrFail($id);
        $stats = app(HabitStatisticsService::class)->calculate($habit, '2026-08-10', '2026-08-17', '2026-08-17');
        $this->assertSame(7, $stats['opportunities']);
        $this->assertSame(3, $stats['successes']);
        $this->assertSame(1, $stats['current_streak']);
    }

    public function test_weekly_validation_ownership_and_local_week_boundary(): void
    {
        $owner = $this->createUser(timezone: 'America/Los_Angeles');
        $other = $this->createUser('other@example.test');
        $hidden = $this->createHabit($other, ['name' => 'Private']);
        CarbonImmutable::setTestNow('2026-08-17 00:30:00 UTC');
        $base = ['name' => 'River', 'kind' => 'habit', 'mode' => 'yes_no', 'schedule_type' => 'weekly_target'];
        $this->actingAs($owner);
        foreach ([0, 8, 2.5, null] as $invalid) {
            $this->postJson('/api/habits', [...$base, 'weekly_target' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('weekly_target');
        }
        $this->postJson('/api/habits', [...$base, 'weekly_target' => 3, 'weekdays' => ['MO']])->assertUnprocessable();
        $this->postJson('/api/habits', [...$base, 'weekly_target' => 3, 'kind' => 'anti_habit', 'mode' => 'abstinence'])
            ->assertUnprocessable()->assertJsonValidationErrors('schedule_type');
        $id = $this->postJson('/api/habits', [...$base, 'weekly_target' => 3])->assertCreated()->json('data.id');
        $this->getJson('/api/today')->assertJsonPath('date', '2026-08-16')
            ->assertJsonCount(1, 'habits')->assertJsonPath('habits.0.weekly_progress.from', '2026-08-10');
        $this->patchJson("/api/habits/{$hidden->id}", ['schedule_type' => 'weekly_target', 'weekly_target' => 3])->assertNotFound();
        $this->patchJson("/api/habits/{$id}", ['schedule_type' => 'daily'])->assertOk()->assertJsonPath('data.schedule.weekly_target', null);
        $this->patchJson("/api/habits/{$id}", ['weekly_target' => 3])->assertUnprocessable();
    }
}
