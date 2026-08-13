<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\Models\FinanceCounterparty;
use Illuminate\Validation\Rule;

class StoreFinanceCounterpartyRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'kind', 'note'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'], 'kind' => ['required', Rule::in(FinanceCounterparty::KINDS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
