<?php

namespace App\Services\Review\Sources;

use App\Contracts\ReviewAggregateSource;
use App\Models\User;
use App\Services\NutritionSummaryService;
use App\Support\NutritionDecimal;
use Carbon\CarbonImmutable;

class NutritionReviewSource implements ReviewAggregateSource
{
    public function __construct(private readonly NutritionSummaryService $summary) {}

    public function key(): string
    {
        return 'nutrition';
    }

    public function daily(User $user, string $date): array
    {
        return $this->summary->forDay($user, $date);
    }

    public function period(User $user, string $from, string $to): array
    {
        $rows = $this->summary->forRange($user, $from, $to);
        $totals = [
            'meal_count' => 0, 'entry_count' => 0, 'calories' => '0.000', 'protein_grams' => '0.000',
            'fat_grams' => '0.000', 'carbs_grams' => '0.000', 'hydration_ml' => '0.000',
        ];
        foreach ($rows as $row) {
            $totals['meal_count'] += $row['meal_count'];
            $totals['entry_count'] += $row['entry_count'];
            foreach (['calories', 'protein_grams', 'fat_grams', 'carbs_grams', 'hydration_ml'] as $field) {
                $totals[$field] = NutritionDecimal::add($totals[$field], $row[$field], 3);
            }
        }

        return [
            'from' => $from, 'to' => $to,
            'days' => CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1,
            ...$totals,
        ];
    }
}
