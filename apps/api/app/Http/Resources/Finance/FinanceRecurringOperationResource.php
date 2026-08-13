<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceRecurringOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'direction' => $this->direction,
            'account' => [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'archived' => $this->account->archived_at !== null,
            ],
            'category' => [
                'id' => $this->category->id,
                'parent_id' => $this->category->parent_id,
                'label' => $this->category->displayLabel(),
                'archived' => $this->category->archived_at !== null,
            ],
            'amount' => (string) $this->amount,
            'currency' => $this->currency_code,
            'mandatory' => $this->is_mandatory,
            'active' => $this->is_active,
            'archived' => $this->is_archived,
            'rule' => [
                'frequency' => 'monthly',
                'interval_months' => $this->recurringRule->interval_count,
                'month_days' => $this->recurringRule->monthdays,
                'starts_on' => $this->recurringRule->starts_on->format('Y-m-d'),
                'ends_on' => $this->recurringRule->ends_on?->format('Y-m-d'),
                'reminder_time' => $this->recurringRule->slot_time
                    ? substr((string) $this->recurringRule->slot_time, 0, 5) : null,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
