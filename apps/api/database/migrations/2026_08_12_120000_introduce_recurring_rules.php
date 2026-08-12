<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move the routine schedule onto the shared recurrence boundary.
 *
 * Feature 001 stored the schedule as columns on `routines` plus a
 * `routine_weekdays` table. Feature 006 makes `recurring_rules` the single
 * authoritative schedule store, so the old shape is backfilled and then removed
 * rather than left alongside as a second writable copy.
 *
 * The sequence is deliberate and matches the one feature 001 used successfully
 * on live data: create the new shape, backfill from the old, then drop. `down()`
 * reverses it by rebuilding the columns from the rules, so the change is
 * reversible on a database that already holds rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('frequency', 16);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('timezone', 64);
            $table->time('slot_time')->nullable();
            $table->date('last_materialized_until')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
            $table->index(['user_id', 'owner_type']);
        });

        Schema::create('recurring_rule_weekdays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_rule_id')->constrained()->cascadeOnDelete();
            $table->string('weekday', 2);
            $table->timestamps();

            $table->unique(['recurring_rule_id', 'weekday']);
        });

        Schema::create('planned_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_rule_id')->constrained()->cascadeOnDelete();
            $table->date('occurrence_date');
            // Empty rather than null: MySQL treats NULLs as distinct in a unique
            // index, which would silently allow duplicate occurrences.
            $table->string('slot', 32)->default('');
            $table->time('occurrence_time')->nullable();
            $table->string('status', 16)->default('planned');
            $table->foreignId('routine_log_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('materialized_at')->nullable();
            $table->timestamps();

            // Named explicitly: the generated name would be 65 characters, one
            // past MySQL's 64-character identifier limit. SQLite accepts it, so
            // only a real MySQL migration surfaces the difference.
            $table->unique(['recurring_rule_id', 'occurrence_date', 'slot'], 'planned_occurrences_rule_date_slot_unique');
            $table->index(['user_id', 'occurrence_date']);
        });

        $this->backfillRules();

        Schema::dropIfExists('routine_weekdays');

        Schema::table('routines', function (Blueprint $table): void {
            $table->dropColumn(['schedule_type', 'preferred_time', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::table('routines', function (Blueprint $table): void {
            $table->string('schedule_type', 16)->default('daily')->after('kind');
            $table->time('preferred_time')->nullable()->after('schedule_type');
            $table->date('starts_on')->nullable()->after('is_active');
            $table->date('ends_on')->nullable()->after('starts_on');
        });

        Schema::create('routine_weekdays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->string('weekday', 2);
            $table->timestamps();

            $table->unique(['routine_id', 'weekday']);
        });

        $this->restoreRoutineColumns();

        Schema::dropIfExists('planned_occurrences');
        Schema::dropIfExists('recurring_rule_weekdays');
        Schema::dropIfExists('recurring_rules');
    }

    /**
     * Create exactly one rule per existing routine and move its weekdays across.
     */
    private function backfillRules(): void
    {
        $now = now();
        $timezones = DB::table('user_profiles')->pluck('timezone', 'user_id');
        $fallback = config('selfhandler.timezone', 'UTC');

        DB::table('routines')->orderBy('id')->chunkById(200, function ($routines) use ($now, $timezones, $fallback): void {
            foreach ($routines as $routine) {
                $ruleId = DB::table('recurring_rules')->insertGetId([
                    'user_id' => $routine->user_id,
                    'owner_type' => 'routine',
                    'owner_id' => $routine->id,
                    'frequency' => $routine->schedule_type === 'weekdays' ? 'weekly' : 'daily',
                    'starts_on' => $routine->starts_on,
                    'ends_on' => $routine->ends_on,
                    'timezone' => $timezones[$routine->user_id] ?? $fallback,
                    'slot_time' => $routine->preferred_time,
                    'last_materialized_until' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $weekdays = DB::table('routine_weekdays')
                    ->where('routine_id', $routine->id)
                    ->pluck('weekday');

                foreach ($weekdays as $weekday) {
                    DB::table('recurring_rule_weekdays')->insert([
                        'user_id' => $routine->user_id,
                        'recurring_rule_id' => $ruleId,
                        'weekday' => $weekday,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    /**
     * Rebuild the feature 001 schedule shape from the rules, so a rollback keeps
     * every schedule that was created after the cutover.
     */
    private function restoreRoutineColumns(): void
    {
        $now = now();

        DB::table('recurring_rules')
            ->where('owner_type', 'routine')
            ->orderBy('id')
            ->chunkById(200, function ($rules) use ($now): void {
                foreach ($rules as $rule) {
                    DB::table('routines')->where('id', $rule->owner_id)->update([
                        'schedule_type' => $rule->frequency === 'weekly' ? 'weekdays' : 'daily',
                        'preferred_time' => $rule->slot_time,
                        'starts_on' => $rule->starts_on,
                        'ends_on' => $rule->ends_on,
                    ]);

                    $weekdays = DB::table('recurring_rule_weekdays')
                        ->where('recurring_rule_id', $rule->id)
                        ->pluck('weekday');

                    foreach ($weekdays as $weekday) {
                        DB::table('routine_weekdays')->insert([
                            'user_id' => $rule->user_id,
                            'routine_id' => $rule->owner_id,
                            'weekday' => $weekday,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }
};
