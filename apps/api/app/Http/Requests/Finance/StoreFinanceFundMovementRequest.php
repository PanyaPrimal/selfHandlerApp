<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Illuminate\Validation\Rule;

class StoreFinanceFundMovementRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['action', 'amount', 'counterparty_account_id', 'occurred_on',
            'reverses_movement_id', 'idempotency_key', 'note'];
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['top_up', 'draw_down', 'reverse'])], 'amount' => ['required_unless:action,reverse', 'prohibited_if:action,reverse', 'string'],
            'counterparty_account_id' => ['present_unless:action,reverse', 'prohibited_if:action,reverse', 'nullable', 'integer', 'min:1'],
            'occurred_on' => ['required_unless:action,reverse', 'prohibited_if:action,reverse', 'date_format:Y-m-d'],
            'reverses_movement_id' => ['required_if:action,reverse', 'prohibited_unless:action,reverse', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:120'], 'note' => ['present', 'nullable', 'string', 'max:5000'],
        ];
    }
}
