<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\Models\FinanceTransactionGroup;
use Illuminate\Validation\Rule;

class StoreFinanceSourceExpenseRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['source_type', 'source_id', 'account_id', 'category_id',
            'amount', 'occurred_on', 'idempotency_key', 'note'];
    }

    public function rules(): array
    {
        return [
            'source_type' => ['required', Rule::in(FinanceTransactionGroup::SOURCE_TYPES)], 'source_id' => ['required', 'integer', 'min:1'],
            'account_id' => ['required', 'integer', 'min:1'], 'category_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string'], 'occurred_on' => ['required', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'max:120'], 'note' => ['present', 'nullable', 'string', 'max:5000'],
        ];
    }
}
