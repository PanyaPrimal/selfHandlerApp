<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day planner: the one fact Planner owns, and where a moved day is recorded.
 *
 * Purely additive. Planner assembles a day from the modules that own its parts,
 * so nothing else is stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('note', 500)->nullable();
            $table->date('block_date');
            // Optional: "dentist, Tuesday" is a real entry before the time is known.
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'block_date']);
        });

        Schema::table('planned_occurrences', function (Blueprint $table): void {
            // The day this occurrence was moved to. `occurrence_date` keeps what
            // the rule expanded: overwriting it would make the next
            // materialization see a missing day and recreate it as a duplicate,
            // and would erase what was originally planned.
            $table->date('rescheduled_to')->nullable()->after('occurrence_date');
        });
    }

    public function down(): void
    {
        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->dropColumn('rescheduled_to');
        });

        Schema::dropIfExists('time_blocks');
    }
};
