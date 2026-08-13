<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('kind', 20);
            $table->string('mode', 32);
            $table->decimal('target_value', 12, 3)->nullable();
            $table->string('unit', 32)->nullable();
            $table->foreignId('routine_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('intention_place', 160)->nullable();
            $table->string('two_minute_starter', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'is_archived', 'is_active'],
                'habits_owner_lifecycle_index',
            );
        });

        Schema::create('habit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            $table->string('outcome', 24);
            $table->decimal('value', 12, 3)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamps();

            $table->unique(['habit_id', 'log_date'], 'habit_logs_habit_date_unique');
            $table->index(['user_id', 'log_date'], 'habit_logs_owner_date_index');
        });

        Schema::create('habit_limit_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->date('effective_on');
            $table->decimal('limit_value', 12, 3);
            $table->string('period', 8);
            $table->timestamps();

            $table->unique(['habit_id', 'effective_on'], 'habit_steps_habit_date_unique');
            $table->index(['user_id', 'effective_on'], 'habit_steps_owner_date_index');
        });

        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->foreignId('habit_log_id')
                ->nullable()
                ->after('routine_log_id')
                ->constrained('habit_logs')
                ->nullOnDelete();
            $table->unique('habit_log_id', 'planned_occ_habit_log_unique');
        });
    }

    public function down(): void
    {
        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->dropUnique('planned_occ_habit_log_unique');
            $table->dropConstrainedForeignId('habit_log_id');
        });

        Schema::dropIfExists('habit_limit_steps');
        Schema::dropIfExists('habit_logs');
        Schema::dropIfExists('habits');
    }
};
