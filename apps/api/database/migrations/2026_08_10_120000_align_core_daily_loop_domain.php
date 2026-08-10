<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns the prototype domain tables with the feature 001 data model.
 *
 * The prototype migration is kept untouched so an installation that already
 * holds real routines, logs, goals, and reviews upgrades in place instead of
 * being rebuilt from scratch.
 */
return new class extends Migration
{
    private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    public function up(): void
    {
        Schema::create('routine_weekdays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->string('weekday', 2);
            $table->timestamps();

            $table->unique(['user_id', 'routine_id', 'weekday']);
            $table->index(['user_id', 'weekday', 'routine_id']);
        });

        $this->normalizeStoredWeekdays();

        Schema::table('routines', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('is_active');
            $table->timestamp('archived_at')->nullable()->after('is_archived');
        });

        // The replacement index is created first: MySQL refuses to drop the last
        // index that covers the `user_id` foreign key.
        Schema::table('routines', function (Blueprint $table) {
            $table->index(['user_id', 'is_archived', 'is_active', 'sort_order']);
        });

        Schema::table('routines', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_active', 'sort_order']);
        });

        Schema::table('routines', function (Blueprint $table) {
            $table->dropColumn('weekdays');
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('status');
            $table->timestamp('archived_at')->nullable()->after('is_archived');
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->index(['user_id', 'is_archived', 'status']);
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('routines', function (Blueprint $table) {
            $table->json('weekdays')->nullable()->after('schedule_type');
        });

        $this->restoreStoredWeekdays();

        Schema::table('routines', function (Blueprint $table) {
            $table->index(['user_id', 'is_active', 'sort_order']);
        });

        Schema::table('routines', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_archived', 'is_active', 'sort_order']);
        });

        Schema::table('routines', function (Blueprint $table) {
            $table->dropColumn(['is_archived', 'archived_at']);
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_archived', 'status']);
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn(['is_archived', 'archived_at']);
        });

        Schema::dropIfExists('routine_weekdays');
    }

    /**
     * Copy the prototype JSON weekday lists into their own rows.
     */
    private function normalizeStoredWeekdays(): void
    {
        $timestamp = now();

        DB::table('routines')
            ->select('id', 'user_id', 'weekdays')
            ->orderBy('id')
            ->chunk(200, function ($routines) use ($timestamp) {
                $rows = [];

                foreach ($routines as $routine) {
                    foreach ($this->decodeWeekdays($routine->weekdays) as $weekday) {
                        $rows[] = [
                            'user_id' => $routine->user_id,
                            'routine_id' => $routine->id,
                            'weekday' => $weekday,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('routine_weekdays')->insertOrIgnore($rows);
                }
            });
    }

    /**
     * Rebuild the prototype JSON weekday lists from their rows.
     */
    private function restoreStoredWeekdays(): void
    {
        DB::table('routine_weekdays')
            ->select('routine_id', 'weekday')
            ->orderBy('routine_id')
            ->get()
            ->groupBy('routine_id')
            ->each(function ($weekdays, int $routineId) {
                $codes = $weekdays
                    ->pluck('weekday')
                    ->all();

                DB::table('routines')
                    ->where('id', $routineId)
                    ->update(['weekdays' => json_encode(array_values(array_intersect(self::WEEKDAYS, $codes)))]);
            });
    }

    /**
     * @return list<string>
     */
    private function decodeWeekdays(mixed $stored): array
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        if (! is_array($decoded)) {
            return [];
        }

        $codes = array_map(
            static fn (mixed $code): string => is_string($code) ? strtoupper(trim($code)) : '',
            $decoded,
        );

        return array_values(array_intersect(self::WEEKDAYS, array_unique($codes)));
    }
};
