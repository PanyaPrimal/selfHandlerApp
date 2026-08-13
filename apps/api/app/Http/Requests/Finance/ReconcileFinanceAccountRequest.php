<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class ReconcileFinanceAccountRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['idempotency_key', 'observed_balance', 'occurred_on', 'reason'];
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'observed_balance' => ['required', 'string', 'max:32'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                Money::of($this->string('observed_balance')->toString(), 'UAH');
            } catch (InvalidArgumentException) {
                $validator->errors()->add('observed_balance', __('messages.finance_money_invalid'));
            }
            if (trim($this->string('reason')->toString()) === '') {
                $validator->errors()->add('reason', __('validation.required', ['attribute' => 'reason']));
            }
            if ($this->date('occurred_on')->toDateString() > CarbonImmutable::now($this->user()->calendarTimezone())->toDateString()) {
                $validator->errors()->add('occurred_on', __('messages.finance_future_date'));
            }
        }];
    }
}
