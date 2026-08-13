<?php

namespace App\Services;

use App\Models\Supplement;
use App\Models\SupplementRestockProposal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SupplementRestockProposalService
{
    /** @param array<string, mixed> $forecast */
    public function reconcile(Supplement $supplement, array $forecast): ?SupplementRestockProposal
    {
        return DB::transaction(function () use ($supplement, $forecast): ?SupplementRestockProposal {
            Supplement::query()->whereKey($supplement->id)->lockForUpdate()->firstOrFail();
            $today = (string) $forecast['as_of'];
            $runout = $forecast['runout_on'];
            $actionable = in_array($forecast['status'], ['ready', 'already_depleted'], true)
                && is_string($runout);
            $threshold = $actionable
                ? CarbonImmutable::parse($runout, $supplement->user->calendarTimezone())
                    ->subDays($supplement->restock_lead_days)->toDateString()
                : null;
            $actionable = $actionable && $threshold <= $today;

            if (! $actionable) {
                $this->resolveOpen($supplement);

                return null;
            }

            $neededBy = max($today, (string) $threshold);
            $fingerprint = hash('sha256', json_encode([
                'supplement_id' => $supplement->id,
                'forecast_runout_on' => $runout,
                'needed_by' => $neededBy,
                'suggested_quantity' => $supplement->package_quantity,
                'stock_unit' => $supplement->stock_unit,
            ], JSON_THROW_ON_ERROR));

            $matching = SupplementRestockProposal::query()
                ->where('supplement_id', $supplement->id)
                ->where('shortage_fingerprint', $fingerprint)
                ->first();
            if ($matching?->status === SupplementRestockProposal::STATUS_DISMISSED) {
                $this->resolveOpen($supplement);

                return null;
            }
            if ($matching?->status === SupplementRestockProposal::STATUS_OPEN) {
                return $matching;
            }
            if ($matching !== null) {
                $this->resolveOpen($supplement);

                return null;
            }

            $this->resolveOpen($supplement);

            return SupplementRestockProposal::create([
                'user_id' => $supplement->user_id,
                'supplement_id' => $supplement->id,
                'active_supplement_id' => $supplement->id,
                'shortage_fingerprint' => $fingerprint,
                'forecast_runout_on' => $runout,
                'needed_by' => $neededBy,
                'suggested_quantity' => $supplement->package_quantity,
                'stock_unit' => $supplement->stock_unit,
                'status' => SupplementRestockProposal::STATUS_OPEN,
            ]);
        });
    }

    public function dismiss(SupplementRestockProposal $proposal): SupplementRestockProposal
    {
        if ($proposal->status !== SupplementRestockProposal::STATUS_OPEN) {
            return $proposal;
        }

        $proposal->forceFill([
            'status' => SupplementRestockProposal::STATUS_DISMISSED,
            'active_supplement_id' => null,
            'dismissed_at' => now(),
        ])->save();

        return $proposal->refresh();
    }

    private function resolveOpen(Supplement $supplement): void
    {
        SupplementRestockProposal::query()
            ->where('supplement_id', $supplement->id)
            ->where('status', SupplementRestockProposal::STATUS_OPEN)
            ->get()
            ->each(function (SupplementRestockProposal $proposal): void {
                $proposal->forceFill([
                    'status' => SupplementRestockProposal::STATUS_RESOLVED,
                    'active_supplement_id' => null,
                    'resolved_at' => now(),
                ])->save();
            });
    }
}
