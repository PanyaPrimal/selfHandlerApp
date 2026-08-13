<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;

class StoreFinanceDebtPaymentRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['planned_occurrence_id', 'amount', 'account_id', 'category_id',
            'occurred_on', 'idempotency_key', 'note'];
    }

    public function rules(): array
    {
        return [
            'planned_occurrence_id' => ['present', 'nullable', 'integer', 'min:1'], 'amount' => ['required', 'string', 'max:32'],
            'account_id' => ['required', 'integer', 'min:1'], 'category_id' => ['required', 'integer', 'min:1'],
            'occurred_on' => ['required', 'date_format:Y-m-d'], 'idempotency_key' => ['required', 'string', 'max:120'],
            'note' => ['present', 'nullable', 'string', 'max:5000'],
        ];
    }
}
