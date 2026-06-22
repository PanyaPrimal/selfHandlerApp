<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default('general');
            $table->string('status')->default('active');
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });

        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind')->default('routine');
            $table->string('schedule_type')->default('daily');
            $table->json('weekdays')->nullable();
            $table->time('preferred_time')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active', 'sort_order']);
        });

        Schema::create('goal_routine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'goal_id', 'routine_id']);
        });

        Schema::create('routine_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            $table->string('status');
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'routine_id', 'log_date']);
            $table->index(['user_id', 'log_date']);
        });

        Schema::create('daily_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('review_date');
            $table->unsignedTinyInteger('mood')->nullable();
            $table->unsignedTinyInteger('energy')->nullable();
            $table->unsignedTinyInteger('stress')->nullable();
            $table->unsignedTinyInteger('day_rating')->nullable();
            $table->text('went_well')->nullable();
            $table->text('improve_tomorrow')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'review_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_reviews');
        Schema::dropIfExists('routine_logs');
        Schema::dropIfExists('goal_routine');
        Schema::dropIfExists('routines');
        Schema::dropIfExists('goals');
    }
};
