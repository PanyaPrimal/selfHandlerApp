<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Illuminate\Validation\Rule;

class StoreFinanceBudgetRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['month', 'category_id', 'limit_amount', 'currency'];
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'category_id' => ['required', 'integer', 'min:1'],
            'limit_amount' => ['required', 'string', 'max:32'],
            'currency' => ['required', Rule::exists('currencies', 'code')->where('is_active', true)],
        ];
    }
}
