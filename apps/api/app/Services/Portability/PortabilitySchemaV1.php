<?php

namespace App\Services\Portability;

use RuntimeException;

class PortabilitySchemaV1
{
    public const VERSION = 1;

    /** @return list<string> */
    public static function excludedOwnedTables(): array
    {
        return ['attachments', 'external_calendar_events', 'integrations', 'llm_audit_events', 'llm_connections',
            'llm_consents', 'llm_settings', 'llm_tool_confirmations', 'notification_settings', 'notifications',
            'sessions', 'synced_items', 'user_profiles'];
    }

    /** @return list<string> */
    public static function exclusionCodes(): array
    {
        return ['account_credentials', 'auth_sessions_tokens', 'invitations', 'framework_runtime',
            'public_catalog_rows', 'notification_deliveries', 'external_integrations'];
    }

    /** @return array<string,array{attributes:list<string>,references:array<string,array<string,mixed>>,json:list<string>}> */
    public static function tables(): array
    {
        return [
            'body_goal_details' => [
                'attributes' => ['metric', 'direction', 'starting_value', 'target_value', 'created_at', 'updated_at'],
                'references' => [
                    'goal_id' => [
                        'table' => 'goals',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'body_measurements' => [
                'attributes' => ['metric', 'measured_on', 'value', 'note', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'daily_reviews' => [
                'attributes' => ['review_date', 'mood', 'energy', 'stress', 'day_rating', 'went_well', 'improve_tomorrow', 'notes', 'completed_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'exercises' => [
                'attributes' => ['system_key', 'name', 'muscle_group', 'equipment', 'exercise_type', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'finance_accounts' => [
                'attributes' => ['name', 'type', 'currency_code', 'archived_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'finance_budget_limits' => [
                'attributes' => ['budget_month', 'limit_amount', 'currency_code', 'created_at', 'updated_at'],
                'references' => [
                    'category_id' => [
                        'table' => 'finance_categories',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_categories' => [
                'attributes' => ['direction', 'parent_scope', 'builtin_key', 'name', 'name_normalized', 'archived_at', 'created_at', 'updated_at'],
                'references' => [
                    'parent_id' => [
                        'table' => 'finance_categories',
                        'nullable' => true,
                    ],
                ],
                'json' => [],
            ],
            'finance_counterparties' => [
                'attributes' => ['name', 'kind', 'note', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'finance_debt_occurrence_details' => [
                'attributes' => ['debt_name', 'direction', 'amount', 'currency_code', 'created_at', 'updated_at'],
                'references' => [
                    'category_id' => [
                        'table' => 'finance_categories',
                        'nullable' => false,
                    ],
                    'account_id' => [
                        'table' => 'finance_accounts',
                        'nullable' => false,
                    ],
                    'finance_debt_id' => [
                        'table' => 'finance_debts',
                        'nullable' => false,
                    ],
                    'planned_occurrence_id' => [
                        'table' => 'planned_occurrences',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_debt_payment_facts' => [
                'attributes' => ['principal_amount', 'currency_code', 'occurred_on', 'created_at', 'updated_at'],
                'references' => [
                    'transaction_group_id' => [
                        'table' => 'finance_transaction_groups',
                        'nullable' => false,
                    ],
                    'planned_occurrence_id' => [
                        'table' => 'planned_occurrences',
                        'nullable' => true,
                    ],
                    'finance_debt_id' => [
                        'table' => 'finance_debts',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_debts' => [
                'attributes' => ['name', 'direction', 'repayment_mode', 'original_amount', 'currency_code', 'originated_on', 'deadline', 'installment_amount', 'installment_count', 'interval_months', 'monthday', 'first_due_on', 'reminder_time', 'note', 'is_active', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [
                    'category_id' => [
                        'table' => 'finance_categories',
                        'nullable' => true,
                    ],
                    'account_id' => [
                        'table' => 'finance_accounts',
                        'nullable' => true,
                    ],
                    'purchase_item_id' => [
                        'table' => 'items',
                        'nullable' => true,
                    ],
                    'finance_counterparty_id' => [
                        'table' => 'finance_counterparties',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_exchange_rates' => [
                'attributes' => ['from_currency', 'to_currency', 'rate_date', 'rate', 'source', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'finance_fund_movements' => [
                'attributes' => ['action', 'delta_amount', 'currency_code', 'occurred_on', 'idempotency_key', 'payload_hash', 'note', 'created_at', 'updated_at'],
                'references' => [
                    'reverses_movement_id' => [
                        'table' => 'finance_fund_movements',
                        'nullable' => true,
                    ],
                    'transaction_group_id' => [
                        'table' => 'finance_transaction_groups',
                        'nullable' => true,
                    ],
                    'finance_saving_fund_id' => [
                        'table' => 'finance_saving_funds',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_fund_occurrence_details' => [
                'attributes' => ['fund_name', 'fund_type', 'storage_mode', 'amount', 'currency_code', 'top_up_mode', 'calculation_basis', 'complete', 'missing_currencies', 'created_at', 'updated_at'],
                'references' => [
                    'category_id' => [
                        'table' => 'finance_categories',
                        'nullable' => true,
                    ],
                    'funding_account_id' => [
                        'table' => 'finance_accounts',
                        'nullable' => true,
                    ],
                    'account_id' => [
                        'table' => 'finance_accounts',
                        'nullable' => false,
                    ],
                    'finance_saving_fund_id' => [
                        'table' => 'finance_saving_funds',
                        'nullable' => false,
                    ],
                    'planned_occurrence_id' => [
                        'table' => 'planned_occurrences',
                        'nullable' => false,
                    ],
                ],
                'json' => ['calculation_basis', 'missing_currencies'],
            ],
            'finance_fund_occurrence_facts' => [
                'attributes' => ['outcome', 'occurred_on', 'created_at', 'updated_at'],
                'references' => [
                    'transaction_group_id' => [
                        'table' => 'finance_transaction_groups',
                        'nullable' => true,
                    ],
                    'finance_fund_movement_id' => [
                        'table' => 'finance_fund_movements',
                        'nullable' => true,
                    ],
                    'planned_occurrence_id' => [
                        'table' => 'planned_occurrences',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_goal_details' => [
                'attributes' => ['kind', 'currency_code', 'created_at', 'updated_at'],
                'references' => [
                    'finance_debt_id' => [
                        'table' => 'finance_debts',
                        'nullable' => true,
                    ],
                    'finance_saving_fund_id' => [
                        'table' => 'finance_saving_funds',
                        'nullable' => true,
                    ],
                    'goal_id' => [
                        'table' => 'goals',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_ledger_entries' => [
                'attributes' => ['role', 'delta_amount', 'currency_code', 'created_at', 'updated_at'],
                'references' => [
                    'category_id' => [
                        'table' => 'finance_categories',
                        'nullable' => true,
                    ],
                    'account_id' => [
                        'table' => 'finance_accounts',
                        'nullable' => false,
                    ],
                    'transaction_group_id' => [
                        'table' => 'finance_transaction_groups',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_occurrence_details' => [
                'attributes' => ['operation_name', 'direction', 'amount', 'currency_code', 'is_mandatory', 'created_at', 'updated_at'],
                'references' => [
                    'finance_recurring_operation_id' => [
                        'table' => 'finance_recurring_operations',
                        'nullable' => false,
                    ],
                    'category_id' => [
                        'table' => 'finance_categories',
                        'nullable' => false,
                    ],
                    'account_id' => [
                        'table' => 'finance_accounts',
                        'nullable' => false,
                    ],
                    'planned_occurrence_id' => [
                        'table' => 'planned_occurrences',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_occurrence_facts' => [
                'attributes' => ['outcome', 'occurred_on', 'created_at', 'updated_at'],
                'references' => [
                    'transaction_group_id' => [
                        'table' => 'finance_transaction_groups',
                        'nullable' => true,
                    ],
                    'planned_occurrence_id' => [
                        'table' => 'planned_occurrences',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_recurring_operations' => [
                'attributes' => ['name', 'direction', 'amount', 'currency_code', 'is_mandatory', 'is_active', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [
                    'category_id' => [
                        'table' => 'finance_categories',
                        'nullable' => false,
                    ],
                    'account_id' => [
                        'table' => 'finance_accounts',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_saving_funds' => [
                'attributes' => ['name', 'fund_type', 'storage_mode', 'currency_code', 'target_mode', 'target_amount', 'deadline', 'top_up_mode', 'fixed_amount', 'income_percent', 'expense_months', 'build_months', 'starts_on', 'monthday', 'reminder_time', 'note', 'is_active', 'is_archived', 'archived_at', 'spent_at', 'created_at', 'updated_at'],
                'references' => [
                    'category_id' => [
                        'table' => 'finance_categories',
                        'nullable' => true,
                    ],
                    'funding_account_id' => [
                        'table' => 'finance_accounts',
                        'nullable' => true,
                    ],
                    'linked_account_key' => [
                        'table' => 'finance_accounts',
                        'nullable' => true,
                    ],
                    'account_id' => [
                        'table' => 'finance_accounts',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'finance_transaction_groups' => [
                'attributes' => ['public_id', 'kind', 'occurred_on', 'idempotency_key', 'payload_hash', 'note', 'tag', 'reversal_reason', 'fx_from_currency', 'fx_to_currency', 'effective_rate', 'created_at', 'updated_at', 'source_type'],
                'references' => [
                    'reverses_group_id' => [
                        'table' => 'finance_transaction_groups',
                        'nullable' => true,
                    ],
                    'source_id' => [
                        'polymorphic' => 'source_type',
                        'nullable' => true,
                    ],
                ],
                'json' => [],
            ],
            'food_items' => [
                'attributes' => ['system_key', 'name', 'basis_unit', 'is_beverage', 'calories_per_100', 'protein_per_100', 'fat_per_100', 'carbs_per_100', 'quality_score', 'hydration_ratio', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'goal_milestones' => [
                'attributes' => ['target_value', 'target_date', 'created_at', 'updated_at'],
                'references' => [
                    'goal_id' => [
                        'table' => 'goals',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'goal_routine' => [
                'attributes' => ['created_at', 'updated_at'],
                'references' => [
                    'routine_id' => [
                        'table' => 'routines',
                        'nullable' => false,
                    ],
                    'goal_id' => [
                        'table' => 'goals',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'goals' => [
                'attributes' => ['name', 'description', 'type', 'status', 'target_date', 'completed_at', 'created_at', 'updated_at', 'deleted_at', 'is_archived', 'archived_at'],
                'references' => [],
                'json' => [],
            ],
            'habit_limit_steps' => [
                'attributes' => ['effective_on', 'limit_value', 'period', 'created_at', 'updated_at'],
                'references' => [
                    'habit_id' => [
                        'table' => 'habits',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'habit_logs' => [
                'attributes' => ['log_date', 'outcome', 'value', 'occurred_at', 'note', 'created_at', 'updated_at'],
                'references' => [
                    'habit_id' => [
                        'table' => 'habits',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'habits' => [
                'attributes' => ['name', 'description', 'kind', 'mode', 'target_value', 'unit', 'intention_place', 'two_minute_starter', 'is_active', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [
                    'goal_id' => [
                        'table' => 'goals',
                        'nullable' => true,
                    ],
                    'routine_id' => [
                        'table' => 'routines',
                        'nullable' => true,
                    ],
                ],
                'json' => [],
            ],
            'item_tag' => [
                'attributes' => ['created_at', 'updated_at'],
                'references' => [
                    'tag_id' => [
                        'table' => 'tags',
                        'nullable' => false,
                    ],
                    'item_id' => [
                        'table' => 'items',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'items' => [
                'attributes' => ['type', 'title', 'description', 'status', 'priority', 'due_on', 'is_blocker', 'completed_at', 'dropped_at', 'created_at', 'updated_at', 'estimated_amount', 'estimated_currency_code'],
                'references' => [
                    'project_id' => [
                        'table' => 'projects',
                        'nullable' => true,
                    ],
                    'parent_id' => [
                        'table' => 'items',
                        'nullable' => true,
                    ],
                ],
                'json' => [],
            ],
            'meal_entries' => [
                'attributes' => ['sort_order', 'reference_name', 'basis_unit', 'quantity', 'calories', 'protein_grams', 'fat_grams', 'carbs_grams', 'hydration_ml', 'quality_numerator', 'quality_denominator', 'created_at', 'updated_at'],
                'references' => [
                    'recipe_id' => [
                        'table' => 'recipes',
                        'nullable' => true,
                    ],
                    'food_item_id' => [
                        'table' => 'food_items',
                        'nullable' => true,
                    ],
                    'meal_id' => [
                        'table' => 'meals',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'meals' => [
                'attributes' => ['consumed_on', 'name', 'category', 'consumed_at_local', 'note', 'submission_key', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'nutrition_daily_targets' => [
                'attributes' => ['target_date', 'status', 'formula', 'bmr_kcal', 'baseline_kcal', 'goal_adjustment_kcal', 'planned_workout_kcal', 'calorie_target', 'protein_target_grams', 'fat_target_grams', 'carbs_target_grams', 'water_target_ml', 'quality_target', 'calculation_basis', 'created_at', 'updated_at'],
                'references' => [],
                'json' => ['calculation_basis'],
            ],
            'nutrition_settings' => [
                'attributes' => ['protein_percent', 'fat_percent', 'carbs_percent', 'water_override_ml', 'created_at', 'updated_at'],
                'references' => [
                    'body_goal_id' => [
                        'table' => 'goals',
                        'nullable' => true,
                    ],
                ],
                'json' => [],
            ],
            'periodic_reviews' => [
                'attributes' => ['period_type', 'period_start', 'period_end', 'period_rating', 'worked_well', 'did_not_work', 'learned', 'next_focus', 'notes', 'completed_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'planned_occurrences' => [
                'attributes' => ['occurrence_date', 'slot', 'occurrence_time', 'status', 'materialized_at', 'created_at', 'updated_at', 'rescheduled_to'],
                'references' => [
                    'finance_fund_occurrence_fact_id' => [
                        'table' => 'finance_fund_occurrence_facts',
                        'nullable' => true,
                    ],
                    'finance_debt_payment_fact_id' => [
                        'table' => 'finance_debt_payment_facts',
                        'nullable' => true,
                    ],
                    'supplement_intake_id' => [
                        'table' => 'supplement_intakes',
                        'nullable' => true,
                    ],
                    'sleep_log_id' => [
                        'table' => 'sleep_logs',
                        'nullable' => true,
                    ],
                    'routine_log_id' => [
                        'table' => 'routine_logs',
                        'nullable' => true,
                    ],
                    'recurring_rule_id' => [
                        'table' => 'recurring_rules',
                        'nullable' => false,
                    ],
                    'habit_log_id' => [
                        'table' => 'habit_logs',
                        'nullable' => true,
                    ],
                    'workout_session_id' => [
                        'table' => 'workout_sessions',
                        'nullable' => true,
                    ],
                    'finance_occurrence_fact_id' => [
                        'table' => 'finance_occurrence_facts',
                        'nullable' => true,
                    ],
                ],
                'json' => [],
            ],
            'projects' => [
                'attributes' => ['name', 'description', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'recipe_components' => [
                'attributes' => ['sort_order', 'quantity_grams', 'created_at', 'updated_at'],
                'references' => [
                    'food_item_id' => [
                        'table' => 'food_items',
                        'nullable' => false,
                    ],
                    'recipe_id' => [
                        'table' => 'recipes',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'recipes' => [
                'attributes' => ['name', 'description', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'recurring_rule_monthdays' => [
                'attributes' => ['monthday', 'created_at', 'updated_at'],
                'references' => [
                    'recurring_rule_id' => [
                        'table' => 'recurring_rules',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'recurring_rule_slots' => [
                'attributes' => ['slot', 'occurrence_time', 'sort_order', 'created_at', 'updated_at'],
                'references' => [
                    'recurring_rule_id' => [
                        'table' => 'recurring_rules',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'recurring_rule_weekdays' => [
                'attributes' => ['weekday', 'created_at', 'updated_at'],
                'references' => [
                    'recurring_rule_id' => [
                        'table' => 'recurring_rules',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'recurring_rules' => [
                'attributes' => ['owner_type', 'frequency', 'starts_on', 'ends_on', 'timezone', 'slot_time', 'last_materialized_until', 'created_at', 'updated_at', 'interval_count', 'cycle_on_days', 'cycle_off_days'],
                'references' => [
                    'owner_id' => [
                        'polymorphic' => 'owner_type',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'routine_activities' => [
                'attributes' => ['name', 'sort_order', 'preferred_time', 'progress_total', 'created_at', 'updated_at'],
                'references' => [
                    'routine_id' => [
                        'table' => 'routines',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'routine_activity_logs' => [
                'attributes' => ['log_date', 'status', 'progress_value', 'note', 'completed_at', 'created_at', 'updated_at'],
                'references' => [
                    'routine_activity_id' => [
                        'table' => 'routine_activities',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'routine_day_selections' => [
                'attributes' => ['selection_date', 'period', 'created_at', 'updated_at'],
                'references' => [
                    'routine_id' => [
                        'table' => 'routines',
                        'nullable' => true,
                    ],
                ],
                'json' => [],
            ],
            'routine_logs' => [
                'attributes' => ['log_date', 'status', 'note', 'completed_at', 'created_at', 'updated_at'],
                'references' => [
                    'routine_id' => [
                        'table' => 'routines',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'routines' => [
                'attributes' => ['name', 'description', 'kind', 'sort_order', 'is_active', 'created_at', 'updated_at', 'deleted_at', 'is_archived', 'archived_at', 'day_period'],
                'references' => [],
                'json' => [],
            ],
            'sleep_logs' => [
                'attributes' => ['sleep_date', 'actual_bed_at', 'actual_wake_at', 'quality', 'note', 'created_at', 'updated_at'],
                'references' => [
                    'sleep_plan_id' => [
                        'table' => 'sleep_plans',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'sleep_occurrence_details' => [
                'attributes' => ['planned_wake_time', 'created_at', 'updated_at'],
                'references' => [
                    'planned_occurrence_id' => [
                        'table' => 'planned_occurrences',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'sleep_plans' => [
                'attributes' => ['name', 'planned_wake_time', 'is_active', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'supplement_course_slots' => [
                'attributes' => ['intake_context', 'created_at', 'updated_at'],
                'references' => [
                    'recurring_rule_slot_id' => [
                        'table' => 'recurring_rule_slots',
                        'nullable' => false,
                    ],
                    'supplement_course_id' => [
                        'table' => 'supplement_courses',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'supplement_courses' => [
                'attributes' => ['name', 'dose_quantity', 'dose_display_unit', 'starts_on', 'ends_on', 'is_active', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [
                    'goal_id' => [
                        'table' => 'goals',
                        'nullable' => true,
                    ],
                    'supplement_id' => [
                        'table' => 'supplements',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'supplement_intakes' => [
                'attributes' => ['planned_on', 'effective_on', 'slot', 'outcome', 'dose_quantity', 'dose_display_unit', 'supplement_name', 'taken_at', 'note', 'created_at', 'updated_at'],
                'references' => [
                    'supplement_id' => [
                        'table' => 'supplements',
                        'nullable' => false,
                    ],
                    'supplement_course_id' => [
                        'table' => 'supplement_courses',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'supplement_restock_proposals' => [
                'attributes' => ['shortage_fingerprint', 'forecast_runout_on', 'needed_by', 'suggested_quantity', 'stock_unit', 'status', 'dismissed_at', 'resolved_at', 'created_at', 'updated_at'],
                'references' => [
                    'active_supplement_id' => [
                        'table' => 'supplements',
                        'nullable' => true,
                    ],
                    'supplement_id' => [
                        'table' => 'supplements',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'supplement_stock_movements' => [
                'attributes' => ['kind', 'quantity_delta', 'effective_on', 'reason', 'note', 'created_at', 'updated_at'],
                'references' => [
                    'supplement_id' => [
                        'table' => 'supplements',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'supplements' => [
                'attributes' => ['name', 'category', 'form', 'stock_unit', 'preferred_display_unit', 'usual_dose_quantity', 'package_quantity', 'restock_lead_days', 'note', 'is_archived', 'archived_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'tags' => [
                'attributes' => ['name', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'time_blocks' => [
                'attributes' => ['title', 'note', 'block_date', 'starts_at', 'ends_at', 'created_at', 'updated_at'],
                'references' => [],
                'json' => [],
            ],
            'training_goal_details' => [
                'attributes' => ['kind', 'activity', 'starting_value', 'target_value', 'created_at', 'updated_at'],
                'references' => [
                    'workout_program_id' => [
                        'table' => 'workout_programs',
                        'nullable' => true,
                    ],
                    'exercise_id' => [
                        'table' => 'exercises',
                        'nullable' => true,
                    ],
                    'goal_id' => [
                        'table' => 'goals',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'workout_endurance_details' => [
                'attributes' => ['activity', 'run_type', 'distance_m', 'average_heart_rate', 'energy_kcal', 'created_at', 'updated_at'],
                'references' => [
                    'workout_session_id' => [
                        'table' => 'workout_sessions',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'workout_program_endurance_details' => [
                'attributes' => ['activity', 'run_type', 'target_distance_m', 'created_at', 'updated_at'],
                'references' => [
                    'workout_program_id' => [
                        'table' => 'workout_programs',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'workout_program_exercises' => [
                'attributes' => ['sort_order', 'target_sets', 'target_reps', 'starting_weight_kg', 'increment_kg', 'successes_required', 'created_at', 'updated_at'],
                'references' => [
                    'exercise_id' => [
                        'table' => 'exercises',
                        'nullable' => false,
                    ],
                    'workout_program_id' => [
                        'table' => 'workout_programs',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'workout_program_timed_details' => [
                'attributes' => ['activity_name', 'created_at', 'updated_at'],
                'references' => [
                    'workout_program_id' => [
                        'table' => 'workout_programs',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'workout_programs' => [
                'attributes' => ['name', 'description', 'workout_type', 'intensity', 'planned_duration_seconds', 'is_active', 'is_archived', 'archived_at', 'created_at', 'updated_at', 'planned_energy_kcal'],
                'references' => [],
                'json' => [],
            ],
            'workout_session_exercises' => [
                'attributes' => ['sort_order', 'simple_weight_kg', 'simple_reps', 'note', 'created_at', 'updated_at'],
                'references' => [
                    'exercise_id' => [
                        'table' => 'exercises',
                        'nullable' => false,
                    ],
                    'workout_session_id' => [
                        'table' => 'workout_sessions',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'workout_sessions' => [
                'attributes' => ['name', 'workout_type', 'outcome', 'performed_on', 'started_at', 'duration_seconds', 'note', 'created_at', 'updated_at'],
                'references' => [
                    'workout_program_id' => [
                        'table' => 'workout_programs',
                        'nullable' => true,
                    ],
                ],
                'json' => [],
            ],
            'workout_sets' => [
                'attributes' => ['set_order', 'weight_kg', 'reps', 'rest_seconds', 'created_at', 'updated_at'],
                'references' => [
                    'workout_session_exercise_id' => [
                        'table' => 'workout_session_exercises',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'workout_strength_details' => [
                'attributes' => ['mode', 'created_at', 'updated_at'],
                'references' => [
                    'workout_session_id' => [
                        'table' => 'workout_sessions',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
            'workout_timed_details' => [
                'attributes' => ['activity_name', 'created_at', 'updated_at'],
                'references' => [
                    'workout_session_id' => [
                        'table' => 'workout_sessions',
                        'nullable' => false,
                    ],
                ],
                'json' => [],
            ],
        ];
    }

    /** @return array<string,array<string,string>> */
    public static function polymorphicMaps(): array
    {
        return [
            'owner_type' => [
                'routine' => 'routines',
                'habit' => 'habits',
                'sleep_plan' => 'sleep_plans',
                'workout_program' => 'workout_programs',
                'supplement_course' => 'supplement_courses',
                'finance_recurring_operation' => 'finance_recurring_operations',
                'finance_debt' => 'finance_debts',
                'finance_saving_fund' => 'finance_saving_funds',
            ],
            'source_type' => [
                'purchase_item' => 'items',
                'supplement_restock_proposal' => 'supplement_restock_proposals',
            ],
        ];
    }

    /** @return list<string> */
    public static function restoreOrder(): array
    {
        $tables = self::tables();
        $pending = array_keys($tables);
        $ordered = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $index => $table) {
                $dependencies = [];
                foreach ($tables[$table]['references'] as $reference) {
                    if (($reference['nullable'] ?? false) === true) {
                        continue;
                    }
                    if (isset($reference['table']) && isset($tables[$reference['table']])) {
                        $dependencies[] = $reference['table'];
                    }
                    if (isset($reference['polymorphic'])) {
                        $dependencies = [...$dependencies, ...array_values(self::polymorphicMaps()[$reference['polymorphic']])];
                    }
                }
                $dependencies = array_values(array_unique(array_filter(
                    $dependencies, fn (string $dependency): bool => $dependency !== $table,
                )));
                if (array_diff($dependencies, $ordered) !== []) {
                    continue;
                }
                $ordered[] = $table;
                unset($pending[$index]);
                $progress = true;
            }
            $pending = array_values($pending);
            if (! $progress) {
                throw new RuntimeException('The portability schema contains a required reference cycle.');
            }
        }

        return $ordered;
    }

    public static function portableId(string $table, int $ordinal): string
    {
        return $table.':'.str_pad((string) $ordinal, 6, '0', STR_PAD_LEFT);
    }
}
