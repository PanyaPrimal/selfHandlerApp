<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreFinanceTransactionRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['idempotency_key', 'kind', 'account_id', 'category_id', 'amount', 'occurred_on', 'note', 'tag'];
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'kind' => ['required', Rule::in(['income', 'expense'])],
            'account_id' => ['required', 'integer', 'min:1'],
            'category_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'max:32'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                $money = Money::of($this->string('amount')->toString(), 'UAH');
                if (bccomp($money->amount(), '0', 4) <= 0) {
                    throw new InvalidArgumentException;
                }
            } catch (InvalidArgumentException) {
                $validator->errors()->add('amount', __('messages.finance_positive_money'));
            }
            if ($this->date('occurred_on')->toDateString() > CarbonImmutable::now($this->user()->calendarTimezone())->toDateString()) {
                $validator->errors()->add('occurred_on', __('messages.finance_future_date'));
            }
        }];
    }
}
