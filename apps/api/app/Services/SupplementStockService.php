<?php

namespace App\Services;

use App\Models\Supplement;
use App\Models\SupplementIntake;
use App\Models\SupplementStockMovement;
use App\Support\NutritionDecimal;
use Illuminate\Support\Collection;

class SupplementStockService
{
    /** @return array{remaining_quantity:string,stock_unit:string,is_negative:bool,has_facts:bool} */
    public function forSupplement(Supplement $supplement): array
    {
        return $this->forMany($supplement->newCollection([$supplement]))[$supplement->id];
    }

    /**
     * @param  Collection<int, Supplement>  $supplements
     * @return array<int, array{remaining_quantity:string,stock_unit:string,is_negative:bool,has_facts:bool}>
     */
    public function forMany(Collection $supplements): array
    {
        if ($supplements->isEmpty()) {
            return [];
        }

        $ids = $supplements->modelKeys();
        $movements = SupplementStockMovement::query()
            ->whereIn('supplement_id', $ids)
            ->selectRaw('supplement_id, SUM(quantity_delta) as quantity, COUNT(*) as fact_count')
            ->groupBy('supplement_id')
            ->get()->keyBy('supplement_id');
        $intakes = SupplementIntake::query()
            ->whereIn('supplement_id', $ids)
            ->where('outcome', SupplementIntake::OUTCOME_TAKEN)
            ->selectRaw('supplement_id, SUM(dose_quantity) as quantity, COUNT(*) as fact_count')
            ->groupBy('supplement_id')
            ->get()->keyBy('supplement_id');

        return $supplements->mapWithKeys(function (Supplement $supplement) use ($movements, $intakes): array {
            $movement = $movements->get($supplement->id);
            $intake = $intakes->get($supplement->id);
            $remaining = NutritionDecimal::add(
                $movement?->quantity ?? '0',
                '-'.ltrim((string) ($intake?->quantity ?? '0'), '+'),
                6,
            );

            return [$supplement->id => [
                'remaining_quantity' => $remaining,
                'stock_unit' => $supplement->stock_unit,
                'is_negative' => bccomp($remaining, '0', 6) < 0,
                'has_facts' => (int) ($movement?->fact_count ?? 0) + (int) ($intake?->fact_count ?? 0) > 0,
            ]];
        })->all();
    }
}
