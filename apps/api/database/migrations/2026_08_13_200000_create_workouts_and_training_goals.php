<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('system_key', 64)->nullable()->unique('exercises_system_key_unique');
            $table->string('name', 160);
            $table->string('muscle_group', 64);
            $table->string('equipment', 64)->nullable();
            $table->string('exercise_type', 32);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name'], 'exercises_owner_name_unique');
            $table->index(['user_id', 'is_archived', 'name'], 'exercises_owner_archive_name_index');
        });

        DB::table('exercises')->insert(array_map(
            fn (array $exercise): array => [
                ...$exercise,
                'user_id' => null,
                'exercise_type' => 'strength',
                'is_archived' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                ['system_key' => 'squat', 'name' => 'Squat', 'muscle_group' => 'legs', 'equipment' => 'barbell'],
                ['system_key' => 'bench_press', 'name' => 'Bench press', 'muscle_group' => 'chest', 'equipment' => 'barbell'],
                ['system_key' => 'deadlift', 'name' => 'Deadlift', 'muscle_group' => 'back', 'equipment' => 'barbell'],
                ['system_key' => 'overhead_press', 'name' => 'Overhead press', 'muscle_group' => 'shoulders', 'equipment' => 'barbell'],
                ['system_key' => 'row', 'name' => 'Row', 'muscle_group' => 'back', 'equipment' => 'barbell'],
                ['system_key' => 'pull_up', 'name' => 'Pull-up', 'muscle_group' => 'back', 'equipment' => 'bodyweight'],
            ],
        ));

        Schema::create('workout_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('workout_type', 24);
            $table->string('intensity', 16);
            $table->unsignedInteger('planned_duration_seconds')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_archived', 'is_active', 'name'], 'workout_programs_owner_lifecycle_index');
        });

        Schema::create('workout_program_exercises', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->unsignedTinyInteger('target_sets');
            $table->unsignedSmallInteger('target_reps');
            $table->decimal('starting_weight_kg', 8, 3);
            $table->decimal('increment_kg', 8, 3);
            $table->unsignedTinyInteger('successes_required');
            $table->timestamps();

            $table->unique(['workout_program_id', 'sort_order'], 'workout_program_exercises_order_unique');
            $table->unique(['workout_program_id', 'exercise_id'], 'workout_program_exercises_item_unique');
            $table->index(['user_id', 'exercise_id'], 'workout_program_exercises_owner_exercise_index');
        });

        Schema::create('workout_program_endurance_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_program_id')->constrained()->cascadeOnDelete();
            $table->string('activity', 24);
            $table->string('run_type', 24)->nullable();
            $table->unsignedInteger('target_distance_m')->nullable();
            $table->timestamps();

            $table->unique('workout_program_id', 'workout_program_endurance_program_unique');
            $table->index(['user_id', 'activity'], 'workout_program_endurance_owner_activity_index');
        });

        Schema::create('workout_program_timed_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_program_id')->constrained()->cascadeOnDelete();
            $table->string('activity_name', 160)->nullable();
            $table->timestamps();

            $table->unique('workout_program_id', 'workout_program_timed_program_unique');
        });

        Schema::create('workout_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('workout_type', 24);
            $table->string('outcome', 16);
            $table->date('performed_on');
            $table->timestamp('started_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'workout_program_id', 'performed_on'], 'workout_sessions_planned_fact_unique');
            $table->index(['user_id', 'performed_on', 'id'], 'workout_sessions_owner_date_index');
            $table->index(['user_id', 'workout_type', 'performed_on'], 'workout_sessions_owner_type_date_index');
        });

        Schema::create('workout_strength_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_session_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 16);
            $table->timestamps();

            $table->unique('workout_session_id', 'workout_strength_session_unique');
        });

        Schema::create('workout_endurance_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_session_id')->constrained()->cascadeOnDelete();
            $table->string('activity', 24);
            $table->string('run_type', 24)->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->unsignedSmallInteger('average_heart_rate')->nullable();
            $table->unsignedInteger('energy_kcal')->nullable();
            $table->timestamps();

            $table->unique('workout_session_id', 'workout_endurance_session_unique');
            $table->index(['user_id', 'activity'], 'workout_endurance_owner_activity_index');
        });

        Schema::create('workout_timed_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_session_id')->constrained()->cascadeOnDelete();
            $table->string('activity_name', 160)->nullable();
            $table->timestamps();

            $table->unique('workout_session_id', 'workout_timed_session_unique');
        });

        Schema::create('workout_session_exercises', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->decimal('simple_weight_kg', 8, 3)->nullable();
            $table->unsignedSmallInteger('simple_reps')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['workout_session_id', 'sort_order'], 'workout_session_exercises_order_unique');
            $table->unique(['workout_session_id', 'exercise_id'], 'workout_session_exercises_item_unique');
            $table->index(['user_id', 'exercise_id'], 'workout_session_exercises_owner_exercise_index');
        });

        Schema::create('workout_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_session_exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('set_order');
            $table->decimal('weight_kg', 8, 3);
            $table->unsignedSmallInteger('reps');
            $table->unsignedInteger('rest_seconds')->nullable();
            $table->timestamps();

            $table->unique(['workout_session_exercise_id', 'set_order'], 'workout_sets_exercise_order_unique');
            $table->index(['user_id', 'workout_session_exercise_id'], 'workout_sets_owner_exercise_index');
        });

        Schema::create('training_goal_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->foreignId('exercise_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('activity', 24)->nullable();
            $table->foreignId('workout_program_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('starting_value', 14, 3)->default(0);
            $table->decimal('target_value', 14, 3);
            $table->timestamps();

            $table->unique('goal_id', 'training_goal_details_goal_unique');
            $table->index(['user_id', 'kind'], 'training_goal_details_owner_kind_index');
            $table->index(['user_id', 'workout_program_id'], 'training_goal_details_owner_program_index');
        });

        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->foreignId('workout_session_id')
                ->nullable()
                ->after('sleep_log_id')
                ->constrained('workout_sessions')
                ->nullOnDelete();
            $table->unique('workout_session_id', 'planned_occ_workout_session_unique');
        });
    }

    public function down(): void
    {
        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->dropUnique('planned_occ_workout_session_unique');
            $table->dropConstrainedForeignId('workout_session_id');
        });

        Schema::dropIfExists('training_goal_details');
        Schema::dropIfExists('workout_sets');
        Schema::dropIfExists('workout_session_exercises');
        Schema::dropIfExists('workout_timed_details');
        Schema::dropIfExists('workout_endurance_details');
        Schema::dropIfExists('workout_strength_details');
        Schema::dropIfExists('workout_sessions');
        Schema::dropIfExists('workout_program_timed_details');
        Schema::dropIfExists('workout_program_endurance_details');
        Schema::dropIfExists('workout_program_exercises');
        Schema::dropIfExists('workout_programs');
        Schema::dropIfExists('exercises');
    }
};
