<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The body measurement log, the body detail of a goal, and goal milestones.
 *
 * Purely additive. `goals` is not reshaped: a body goal is an ordinary goal with
 * `type = 'body'` plus the detail row created here, so every existing goal
 * behaviour and contract is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('body_measurements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 32);
            $table->date('measured_on');
            // Decimal, not float: a value converted to a display unit and back
            // must return exactly what the user typed.
            $table->decimal('value', 12, 4);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            // One observation per metric per calendar day. Saving the same
            // combination again is a correction, not a second observation.
            $table->unique(['user_id', 'metric', 'measured_on']);
            $table->index(['user_id', 'metric', 'measured_on'], 'body_measurements_history_index');
        });

        Schema::create('body_goal_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('metric', 32);
            $table->string('direction', 16);
            $table->decimal('starting_value', 12, 4);
            $table->decimal('target_value', 12, 4);
            $table->timestamps();

            $table->index(['user_id', 'metric']);
        });

        Schema::create('goal_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->decimal('target_value', 12, 4);
            $table->date('target_date')->nullable();
            $table->timestamps();

            // No `achieved_at`: achievement is derived from the measurement
            // history at read time, so it can never contradict the observations.
            $table->unique(['goal_id', 'target_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_milestones');
        Schema::dropIfExists('body_goal_details');
        Schema::dropIfExists('body_measurements');
    }
};
