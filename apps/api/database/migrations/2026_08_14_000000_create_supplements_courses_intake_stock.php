<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('category', 32);
            $table->string('form', 24);
            $table->string('stock_unit', 16);
            $table->string('preferred_display_unit', 8);
            $table->decimal('usual_dose_quantity', 14, 6);
            $table->decimal('package_quantity', 14, 6)->nullable();
            $table->unsignedSmallInteger('restock_lead_days')->default(7);
            $table->text('note')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_archived', 'name', 'id'], 'supplements_owner_archive_name_idx');
        });

        Schema::create('supplement_courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplement_id')->constrained();
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160)->nullable();
            $table->decimal('dose_quantity', 14, 6);
            $table->string('dose_display_unit', 8);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'is_archived', 'is_active', 'starts_on', 'ends_on'],
                'supp_courses_owner_lifecycle_dates_idx',
            );
            $table->index(['user_id', 'supplement_id'], 'supp_courses_owner_supplement_idx');
        });

        Schema::table('recurring_rules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('interval_count')->default(1)->after('frequency');
            $table->unsignedSmallInteger('cycle_on_days')->nullable()->after('interval_count');
            $table->unsignedSmallInteger('cycle_off_days')->nullable()->after('cycle_on_days');
        });

        Schema::create('recurring_rule_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_rule_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 32);
            $table->time('occurrence_time');
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();

            $table->unique(['recurring_rule_id', 'slot'], 'rule_slots_rule_slot_unique');
            $table->unique(['recurring_rule_id', 'sort_order'], 'rule_slots_rule_order_unique');
            $table->index(['user_id', 'occurrence_time'], 'rule_slots_owner_time_idx');
        });

        Schema::create('supplement_course_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplement_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_rule_slot_id')->constrained()->cascadeOnDelete();
            $table->string('intake_context', 24);
            $table->timestamps();

            $table->unique('recurring_rule_slot_id', 'supp_course_slots_rule_slot_unique');
            $table->unique(
                ['supplement_course_id', 'recurring_rule_slot_id'],
                'supp_course_slots_course_rule_unique',
            );
            $table->index(['user_id', 'supplement_course_id'], 'supp_course_slots_owner_course_idx');
        });

        Schema::create('supplement_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplement_course_id')->constrained();
            $table->foreignId('supplement_id')->constrained();
            $table->date('planned_on');
            $table->date('effective_on');
            $table->string('slot', 32);
            $table->string('outcome', 16);
            $table->decimal('dose_quantity', 14, 6);
            $table->string('dose_display_unit', 8);
            $table->string('supplement_name', 160);
            $table->timestamp('taken_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(
                ['supplement_course_id', 'planned_on', 'slot'],
                'supp_intake_course_day_slot_unique',
            );
            $table->index(['user_id', 'effective_on', 'id'], 'supp_intakes_owner_effective_idx');
            $table->index(['user_id', 'supplement_id', 'outcome'], 'supp_intakes_owner_supp_outcome_idx');
        });

        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->foreignId('supplement_intake_id')
                ->nullable()
                ->after('workout_session_id')
                ->constrained('supplement_intakes')
                ->nullOnDelete();
            $table->unique('supplement_intake_id', 'planned_occ_supp_intake_unique');
        });

        Schema::create('supplement_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplement_id')->constrained();
            $table->string('kind', 16);
            $table->decimal('quantity_delta', 14, 6);
            $table->date('effective_on');
            $table->string('reason', 500)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'supplement_id', 'effective_on', 'id'],
                'supp_stock_owner_supp_date_idx',
            );
            $table->index(['user_id', 'effective_on', 'id'], 'supp_stock_owner_date_idx');
        });

        Schema::create('supplement_restock_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_supplement_id')->nullable()->constrained('supplements')->cascadeOnDelete();
            $table->string('shortage_fingerprint', 64);
            $table->date('forecast_runout_on');
            $table->date('needed_by');
            $table->decimal('suggested_quantity', 14, 6)->nullable();
            $table->string('stock_unit', 16);
            $table->string('status', 16);
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique('active_supplement_id', 'supp_restock_active_supp_unique');
            $table->unique(
                ['supplement_id', 'shortage_fingerprint'],
                'supp_restock_supp_fingerprint_unique',
            );
            $table->index(['user_id', 'status', 'needed_by'], 'supp_restock_owner_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->dropUnique('planned_occ_supp_intake_unique');
            $table->dropConstrainedForeignId('supplement_intake_id');
        });

        Schema::dropIfExists('supplement_restock_proposals');
        Schema::dropIfExists('supplement_stock_movements');
        Schema::dropIfExists('supplement_intakes');
        Schema::dropIfExists('supplement_course_slots');
        Schema::dropIfExists('recurring_rule_slots');
        Schema::dropIfExists('supplement_courses');
        Schema::dropIfExists('supplements');

        Schema::table('recurring_rules', function (Blueprint $table): void {
            $table->dropColumn(['interval_count', 'cycle_on_days', 'cycle_off_days']);
        });
    }
};
