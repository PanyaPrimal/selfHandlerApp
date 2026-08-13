<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sleep_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->time('planned_wake_time');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_archived', 'is_active'], 'sleep_plans_owner_lifecycle_index');
            $table->index(['user_id', 'name'], 'sleep_plans_owner_name_index');
        });

        Schema::create('sleep_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sleep_plan_id')->constrained()->restrictOnDelete();
            $table->date('sleep_date');
            $table->timestamp('actual_bed_at');
            $table->timestamp('actual_wake_at');
            $table->unsignedTinyInteger('quality');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'sleep_date'], 'sleep_logs_owner_date_unique');
            $table->unique(['sleep_plan_id', 'sleep_date'], 'sleep_logs_plan_date_unique');
            $table->index(['user_id', 'actual_bed_at'], 'sleep_logs_owner_bed_index');
        });

        Schema::create('sleep_occurrence_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_occurrence_id')->constrained()->cascadeOnDelete();
            $table->time('planned_wake_time');
            $table->timestamps();

            $table->unique('planned_occurrence_id', 'sleep_details_occurrence_unique');
            $table->index(['user_id', 'planned_wake_time'], 'sleep_details_owner_wake_index');
        });

        Schema::create('routine_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('sort_order');
            $table->time('preferred_time')->nullable();
            $table->decimal('progress_total', 10, 3)->nullable();
            $table->timestamps();

            $table->unique(['routine_id', 'sort_order'], 'routine_activities_order_unique');
            $table->index(['user_id', 'routine_id'], 'routine_activities_owner_routine_index');
        });

        Schema::create('routine_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routine_activity_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            $table->string('status', 16);
            $table->decimal('progress_value', 10, 3)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['routine_activity_id', 'log_date'], 'routine_activity_logs_fact_unique');
            $table->index(['user_id', 'log_date'], 'routine_activity_logs_owner_date_index');
        });

        Schema::create('routine_day_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('selection_date');
            $table->string('period', 16);
            $table->foreignId('routine_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'selection_date', 'period'], 'routine_selections_owner_date_period_unique');
            $table->index(['user_id', 'routine_id'], 'routine_selections_owner_routine_index');
        });

        Schema::table('routines', function (Blueprint $table): void {
            $table->string('day_period', 16)->default('anytime')->after('kind');
            $table->index(['user_id', 'day_period', 'is_archived'], 'routines_owner_period_archive_index');
        });

        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->foreignId('sleep_log_id')
                ->nullable()
                ->after('habit_log_id')
                ->constrained('sleep_logs')
                ->nullOnDelete();
            $table->unique('sleep_log_id', 'planned_occ_sleep_log_unique');
        });
    }

    public function down(): void
    {
        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->dropUnique('planned_occ_sleep_log_unique');
            $table->dropConstrainedForeignId('sleep_log_id');
        });

        Schema::table('routines', function (Blueprint $table): void {
            $table->dropIndex('routines_owner_period_archive_index');
            $table->dropColumn('day_period');
        });

        Schema::dropIfExists('routine_day_selections');
        Schema::dropIfExists('routine_activity_logs');
        Schema::dropIfExists('routine_activities');
        Schema::dropIfExists('sleep_occurrence_details');
        Schema::dropIfExists('sleep_logs');
        Schema::dropIfExists('sleep_plans');
    }
};
