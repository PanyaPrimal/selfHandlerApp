<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Illuminate\Validation\Validator;

class ReverseFinanceTransactionRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['idempotency_key', 'reason'];
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->has('reason') && trim($this->string('reason')->toString()) === '') {
                $validator->errors()->add('reason', __('validation.required', ['attribute' => 'reason']));
            }
        }];
    }
}
