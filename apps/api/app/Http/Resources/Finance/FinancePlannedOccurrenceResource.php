<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancePlannedOccurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->financeDetail;
        $fact = $this->financeOccurrenceFact;

        return [
            'id' => $this->id,
            'operation_id' => $detail->finance_recurring_operation_id,
            'operation_name' => $detail->operation_name,
            'planned_on' => $this->occurrence_date->format('Y-m-d'),
            'effective_on' => $this->rescheduled_to?->format('Y-m-d') ?? $this->occurrence_date->format('Y-m-d'),
            'moved' => $this->rescheduled_to !== null,
            'reminder_time' => $this->occurrence_time ? substr((string) $this->occurrence_time, 0, 5) : null,
            'status' => $fact?->outcome ?? 'planned',
            'direction' => $detail->direction,
            'account' => [
                'id' => $detail->account->id,
                'name' => $detail->account->name,
                'archived' => $detail->account->archived_at !== null,
            ],
            'category' => [
                'id' => $detail->category->id,
                'parent_id' => $detail->category->parent_id,
                'label' => $detail->category->displayLabel(),
                'archived' => $detail->category->archived_at !== null,
            ],
            'amount' => (string) $detail->amount,
            'currency' => $detail->currency_code,
            'mandatory' => $detail->is_mandatory,
            'outcome' => $fact ? [
                'type' => $fact->outcome,
                'transaction_id' => $fact->transactionGroup?->public_id,
                'occurred_on' => $fact->occurred_on?->format('Y-m-d'),
                'created_at' => $fact->created_at?->toISOString(),
            ] : null,
        ];
    }
}
