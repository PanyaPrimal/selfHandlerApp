<?php

namespace App\Services;

use App\Models\User;

/** Remove restricted private history only as part of deleting its entire owner. */
final class UserHistoryDeletion
{
    public function delete(User $user): void
    {
        $connection = $user->getConnection();

        // MySQL checks RESTRICT immediately, including during a users FK cascade.
        // Keep those guards for ordinary history deletion; clear dependants first.
        foreach ([
            'finance_goal_details',
            'finance_fund_occurrence_facts',
            'finance_debt_payment_facts',
            'finance_occurrence_facts',
            'finance_fund_occurrence_details',
            'finance_debt_occurrence_details',
            'finance_occurrence_details',
        ] as $table) {
            $connection->table($table)->where('user_id', $user->getKey())->delete();
        }

        foreach ([
            'finance_fund_movements' => 'reverses_movement_id',
            'finance_transaction_groups' => 'reverses_group_id',
        ] as $table => $reference) {
            $connection->table($table)->where('user_id', $user->getKey())
                ->whereNotNull($reference)->update([$reference => null]);
        }

        foreach ([
            'finance_fund_movements',
            'finance_debts',
            'finance_saving_funds',
            'finance_recurring_operations',
            'finance_budget_limits',
            'finance_ledger_entries',
            'finance_transaction_groups',
            'sleep_logs',
            'workout_program_exercises',
            'workout_session_exercises',
            'training_goal_details',
            'workout_sessions',
            'recipe_components',
            'supplement_intakes',
            'supplement_stock_movements',
        ] as $table) {
            $connection->table($table)->where('user_id', $user->getKey())->delete();
        }

        // Categories have at most two levels. Delete children before root cascades.
        $connection->table('finance_categories')->where('user_id', $user->getKey())
            ->whereNotNull('parent_id')->delete();
    }
}
