<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_programs', function (Blueprint $table): void {
            $table->unsignedInteger('planned_energy_kcal')->nullable()->after('planned_duration_seconds');
        });

        Schema::create('food_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('system_key', 64)->nullable()->unique();
            $table->string('name', 160);
            $table->string('basis_unit', 16);
            $table->boolean('is_beverage');
            $table->decimal('calories_per_100', 10, 3);
            $table->decimal('protein_per_100', 10, 3);
            $table->decimal('fat_per_100', 10, 3);
            $table->decimal('carbs_per_100', 10, 3);
            $table->decimal('quality_score', 5, 2)->nullable();
            $table->decimal('hydration_ratio', 5, 4)->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_archived', 'name'], 'food_items_owner_state_name_index');
        });

        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('description', 1000)->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_archived', 'name'], 'recipes_owner_state_name_index');
        });

        Schema::create('recipe_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_item_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->decimal('quantity_grams', 10, 3);
            $table->timestamps();

            $table->unique(['recipe_id', 'sort_order'], 'recipe_components_order_unique');
            $table->unique(['recipe_id', 'food_item_id'], 'recipe_components_food_unique');
        });

        Schema::create('meals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('consumed_on');
            $table->string('name', 160);
            $table->string('category', 24)->nullable();
            $table->time('consumed_at_local')->nullable();
            $table->string('note', 1000)->nullable();
            $table->uuid('submission_key');
            $table->timestamps();

            $table->unique(['user_id', 'submission_key'], 'meals_owner_submission_unique');
            $table->index(['user_id', 'consumed_on', 'consumed_at_local', 'id'], 'meals_owner_day_time_index');
        });

        Schema::create('meal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->string('reference_name', 160);
            $table->string('basis_unit', 16);
            $table->decimal('quantity', 10, 3);
            $table->decimal('calories', 12, 3);
            $table->decimal('protein_grams', 12, 3);
            $table->decimal('fat_grams', 12, 3);
            $table->decimal('carbs_grams', 12, 3);
            $table->decimal('hydration_ml', 12, 3);
            $table->decimal('quality_numerator', 16, 4)->nullable();
            $table->decimal('quality_denominator', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['meal_id', 'sort_order'], 'meal_entries_order_unique');
            $table->index(['user_id', 'meal_id'], 'meal_entries_owner_meal_index');
        });

        Schema::create('nutrition_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('body_goal_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->decimal('protein_percent', 5, 2)->default(20);
            $table->decimal('fat_percent', 5, 2)->default(30);
            $table->decimal('carbs_percent', 5, 2)->default(50);
            $table->unsignedSmallInteger('water_override_ml')->nullable();
            $table->timestamps();
        });

        Schema::create('nutrition_daily_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('target_date');
            $table->string('status', 16);
            $table->string('formula', 32);
            $table->decimal('bmr_kcal', 10, 2)->nullable();
            $table->decimal('baseline_kcal', 10, 2)->nullable();
            $table->integer('goal_adjustment_kcal')->default(0);
            $table->unsignedInteger('planned_workout_kcal')->default(0);
            $table->unsignedInteger('calorie_target')->nullable();
            $table->decimal('protein_target_grams', 10, 2)->nullable();
            $table->decimal('fat_target_grams', 10, 2)->nullable();
            $table->decimal('carbs_target_grams', 10, 2)->nullable();
            $table->unsignedSmallInteger('water_target_ml')->nullable();
            $table->decimal('quality_target', 5, 2)->default(70);
            $table->json('calculation_basis');
            $table->timestamps();

            $table->unique(['user_id', 'target_date'], 'nutrition_targets_owner_date_unique');
        });

        DB::table('food_items')->insert([
            'user_id' => null,
            'system_key' => 'plain_water',
            'name' => 'Plain water',
            'basis_unit' => 'millilitre',
            'is_beverage' => true,
            'calories_per_100' => 0,
            'protein_per_100' => 0,
            'fat_per_100' => 0,
            'carbs_per_100' => 0,
            'quality_score' => null,
            'hydration_ratio' => 1,
            'is_archived' => false,
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_daily_targets');
        Schema::dropIfExists('nutrition_settings');
        Schema::dropIfExists('meal_entries');
        Schema::dropIfExists('meals');
        Schema::dropIfExists('recipe_components');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('food_items');

        Schema::table('workout_programs', function (Blueprint $table): void {
            $table->dropColumn('planned_energy_kcal');
        });
    }
};
