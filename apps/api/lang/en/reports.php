<?php

return [
    'title' => 'Analytics report', 'subtitle' => 'Exact SelfHandler trend values and evidence coverage.',
    'filename' => 'selfhandler-:metric-:from-:to', 'generated_at' => 'Generated at (UTC)', 'metric' => 'Metric',
    'range' => 'Selected range', 'granularity' => 'Grouping', 'aggregation' => 'Aggregation', 'unit' => 'Unit',
    'available_points' => 'Available intervals', 'total_intervals' => 'All intervals', 'first' => 'First value',
    'last' => 'Last value', 'delta' => 'Change', 'slope' => 'Slope per interval', 'comparison' => 'Preceding range',
    'current_value' => 'Current value', 'previous_value' => 'Preceding value', 'absolute_delta' => 'Absolute change',
    'percentage_delta' => 'Relative change (%)', 'period_start' => 'Interval start', 'period_end' => 'Interval end',
    'value' => 'Value', 'state' => 'Evidence state', 'samples' => 'Samples', 'reason' => 'Evidence limitation',
    'evidence_note' => 'Missing and incomplete evidence is not treated as zero. This descriptive report is not medical or financial advice.',
    'states' => ['ready' => 'Available', 'empty' => 'No evidence', 'incomplete' => 'Incomplete'],
    'granularities' => ['daily' => 'Day', 'weekly' => 'Week', 'monthly' => 'Month'],
    'aggregations' => ['sum' => 'Sum', 'mean' => 'Arithmetic mean', 'percentage' => 'Weighted percentage', 'last' => 'Last observation'],
    'units' => ['percent' => 'Percent', 'minutes' => 'Minutes', 'count' => 'Count', 'rating_5' => 'Rating out of 5', 'rating_10' => 'Rating out of 10', 'kilograms' => 'Kilograms'],
    'reasons' => ['missing_fx' => 'Missing exchange rate for :currency', 'missing_evidence' => 'Missing evidence'],
    'metrics' => [
        'routines' => ['completion_rate' => 'Routine completion'], 'sleep' => ['duration_minutes' => 'Sleep duration', 'quality' => 'Sleep quality'],
        'workouts' => ['completed_sessions' => 'Completed workouts', 'duration_minutes' => 'Workout duration'],
        'nutrition' => ['calorie_target_adherence' => 'Calorie target adherence'], 'supplements' => ['adherence' => 'Supplement adherence'],
        'habits' => ['completion_rate' => 'Habit completion'], 'planner' => ['completion_rate' => 'Planner completion'],
        'finance' => ['income' => 'Income', 'expense' => 'Expense', 'net' => 'Net cash flow'],
        'review' => ['energy' => 'Energy', 'mood' => 'Mood', 'stress' => 'Stress', 'day_rating' => 'Day rating'],
        'body' => ['body_mass' => 'Body mass'],
    ],
];
