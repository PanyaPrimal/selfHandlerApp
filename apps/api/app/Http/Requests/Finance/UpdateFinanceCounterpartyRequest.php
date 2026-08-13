<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\Models\FinanceCounterparty;
use Illuminate\Validation\Rule;

class UpdateFinanceCounterpartyRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'kind', 'note', 'archived'];
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'], 'kind' => ['sometimes', Rule::in(FinanceCounterparty::KINDS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'], 'archived' => ['sometimes', 'boolean'],
        ];
    }
}
