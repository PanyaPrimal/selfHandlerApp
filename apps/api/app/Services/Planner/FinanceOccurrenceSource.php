<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use App\Support\PlannerEntry;

class FinanceOccurrenceSource implements SchedulableSource
{
    public function name(): string
    {
        return 'finance';
    }

    public function entriesFor(User $user, string $date): array
    {
        return PlannedOccurrence::query()->ownedBy($user)
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()->ownedBy($user)
                ->where('owner_type', RecurringRule::OWNER_FINANCE_RECURRING_OPERATION)->select('id'))
            ->with(['financeDetail.operation', 'financeOccurrenceFact'])
            ->orderBy('occurrence_time')->orderBy('id')->get()
            ->map(function (PlannedOccurrence $occurrence) use ($date): ?PlannerEntry {
                $detail = $occurrence->financeDetail;
                $operation = $detail?->operation;
                if (! $detail || ! $operation || ($occurrence->status === PlannedOccurrence::STATUS_PLANNED
                    && (! $operation->is_active || $operation->is_archived))) {
                    return null;
                }
                $status = $occurrence->financeOccurrenceFact?->outcome === 'actual'
                    ? PlannedOccurrence::STATUS_DONE : $occurrence->status;

                return new PlannerEntry(
                    source: $this->name(),
                    sourceId: $occurrence->id,
                    title: $detail->operation_name,
                    time: $occurrence->occurrence_time ? substr((string) $occurrence->occurrence_time, 0, 5) : null,
                    status: $status,
                    actions: $occurrence->hasFact() ? [] : ['actualize', 'skip', 'reschedule'],
                    meta: [
                        'operation_id' => $operation->id,
                        'direction' => $detail->direction,
                        'amount' => (string) $detail->amount,
                        'currency' => $detail->currency_code,
                        'mandatory' => $detail->is_mandatory,
                        'occurrence_date' => $occurrence->occurrence_date->format('Y-m-d'),
                        'rescheduled_to' => $occurrence->rescheduled_to?->format('Y-m-d'),
                        'action_url' => '/finance?tab=plans&month='.substr($date, 0, 7).'&occurrence='.$occurrence->id,
                    ],
                );
            })->filter()->values()->all();
    }
}
