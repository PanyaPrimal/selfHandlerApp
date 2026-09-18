<?php

namespace Tests\Feature\Auth;

use App\Models\FinanceGoalDetail;
use App\Models\FinanceTransactionGroup;
use App\Models\Goal;
use App\Models\User;
use App\Services\Finance\FinanceDebtPaymentService;
use App\Services\Finance\FinanceFundMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\FinanceTestCase;

class UserDeletionTest extends FinanceTestCase
{
    public function test_deletion_removes_connected_history_and_preserves_other_owners_and_catalogs(): void
    {
        $owner = $this->populatedOwner();
        $other = $this->populatedOwner();
        $otherHistory = $this->history($other);
        $currencies = DB::table('currencies')->get()->toJson();
        $systemExercises = DB::table('exercises')->whereNull('user_id')->get()->toJson();

        $this->assertTrue($owner->delete());

        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertSame($otherHistory, $this->history($other));
        foreach ($this->history($owner) as $rows) {
            $this->assertSame('[]', $rows);
        }
        $this->assertSame($currencies, DB::table('currencies')->get()->toJson());
        $this->assertSame($systemExercises, DB::table('exercises')->whereNull('user_id')->get()->toJson());
    }

    public function test_an_observer_failure_rolls_back_the_entire_history(): void
    {
        $owner = $this->populatedOwner();
        $before = $this->history($owner);
        $level = DB::transactionLevel();
        Event::listen('eloquent.deleting: '.User::class, static function (): void {
            throw new RuntimeException('forced owner deletion failure');
        });

        try {
            $owner->delete();
            $this->fail('The observer failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced owner deletion failure', $exception->getMessage());
        }

        $this->assertTrue($owner->exists);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($before, $this->history($owner));
    }

    public function test_a_deleting_veto_preserves_history(): void
    {
        $owner = $this->populatedOwner();
        $before = $this->history($owner);
        Event::listen('eloquent.deleting: '.User::class, static fn (): bool => false);

        $this->assertFalse($owner->delete());
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertSame($before, $this->history($owner));
    }

    public function test_sleep_training_and_recipe_history_do_not_block_owner_deletion(): void
    {
        $owner = $this->owner();
        $owned = ['user_id' => $owner->id];
        $plan = DB::table('sleep_plans')->insertGetId($owned + [
            'name' => 'Night', 'planned_wake_time' => '07:00',
        ]);
        DB::table('sleep_logs')->insert($owned + [
            'sleep_plan_id' => $plan, 'sleep_date' => '2026-08-13', 'quality' => 4,
            'actual_bed_at' => '2026-08-12 23:00:00', 'actual_wake_at' => '2026-08-13 07:00:00',
        ]);
        $exercise = DB::table('exercises')->insertGetId($owned + [
            'name' => 'Private exercise', 'muscle_group' => 'legs', 'exercise_type' => 'strength',
        ]);
        $program = DB::table('workout_programs')->insertGetId($owned + [
            'name' => 'Training', 'workout_type' => 'strength', 'intensity' => 'moderate',
        ]);
        DB::table('workout_program_exercises')->insert($owned + [
            'workout_program_id' => $program, 'exercise_id' => $exercise, 'sort_order' => 0,
            'target_sets' => 3, 'target_reps' => 5, 'starting_weight_kg' => 20,
            'increment_kg' => 1, 'successes_required' => 2,
        ]);
        $food = DB::table('food_items')->insertGetId($owned + [
            'name' => 'Private food', 'basis_unit' => 'g', 'is_beverage' => false,
            'calories_per_100' => 100, 'protein_per_100' => 10, 'fat_per_100' => 5, 'carbs_per_100' => 5,
        ]);
        $recipe = DB::table('recipes')->insertGetId($owned + ['name' => 'Private recipe']);
        DB::table('recipe_components')->insert($owned + [
            'recipe_id' => $recipe, 'food_item_id' => $food, 'sort_order' => 0, 'quantity_grams' => 100,
        ]);

        $this->assertTrue($owner->delete());

        foreach (['sleep_logs', 'sleep_plans', 'exercises', 'workout_programs',
            'workout_program_exercises', 'food_items', 'recipes', 'recipe_components'] as $table) {
            $this->assertDatabaseMissing($table, $owned);
        }
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
    }

    private function populatedOwner(): User
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $parent = $this->category($owner);
        $child = $this->childCategory($owner, $parent);
        $entry = $this->entry($owner, $account, '1000.0000');
        FinanceTransactionGroup::factory()->create([
            'user_id' => $owner->id, 'kind' => 'reversal',
            'reverses_group_id' => $entry->transaction_group_id,
        ]);
        $debt = $this->flexibleDebt($owner, $this->counterparty($owner), $account, $child);
        app(FinanceDebtPaymentService::class)->pay($owner, $debt, [
            'planned_occurrence_id' => null, 'amount' => '60.0000',
            'account_id' => $account->id, 'category_id' => $child->id,
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'delete-debt', 'note' => null,
        ]);
        $fund = $this->regularFund($owner, $account);
        $movements = app(FinanceFundMovementService::class);
        [$topUp] = $movements->move($owner, $fund, [
            'action' => 'top_up', 'amount' => '80.0000', 'counterparty_account_id' => null,
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'delete-topup', 'note' => null,
        ]);
        $movements->move($owner, $fund, [
            'action' => 'reverse', 'amount' => null, 'counterparty_account_id' => null,
            'reverses_movement_id' => $topUp->id, 'occurred_on' => null,
            'idempotency_key' => 'delete-reverse', 'note' => 'Correction',
        ]);
        FinanceGoalDetail::factory()->create([
            'user_id' => $owner->id,
            'goal_id' => Goal::factory()->create(['user_id' => $owner->id, 'type' => Goal::TYPE_FINANCE])->id,
            'kind' => 'save', 'finance_saving_fund_id' => $fund->id,
        ]);
        $this->recurringOperation($owner);

        return $owner;
    }

    /** @return array<string, string> */
    private function history(User $owner): array
    {
        $history = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (str_starts_with($table, 'finance_')) {
                $history[$table] = DB::table($table)->where('user_id', $owner->id)->orderBy('id')->get()->toJson();
            }
        }

        $this->assertNotEmpty($history);

        return $history;
    }
}
