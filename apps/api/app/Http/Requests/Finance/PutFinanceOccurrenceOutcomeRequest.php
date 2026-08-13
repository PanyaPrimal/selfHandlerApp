<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Illuminate\Validation\Rule;

class PutFinanceOccurrenceOutcomeRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['outcome'];
    }

    public function rules(): array
    {
        return ['outcome' => ['required', Rule::in(['actual', 'skipped'])]];
    }
}
