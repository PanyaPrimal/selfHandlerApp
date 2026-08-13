<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertFinanceExchangeRateRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['from_currency', 'to_currency', 'rate_date', 'rate'];
    }

    public function rules(): array
    {
        $currency = Rule::exists('currencies', 'code')->where('is_active', true);

        return [
            'from_currency' => ['required', $currency],
            'to_currency' => ['required', Rule::exists('currencies', 'code')->where('is_active', true)],
            'rate_date' => ['required', 'date_format:Y-m-d'],
            'rate' => ['required', 'string', 'max:32', 'regex:/^\d+(?:\.\d{1,12})?$/'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->input('from_currency') === $this->input('to_currency')) {
                $validator->errors()->add('to_currency', __('messages.finance_rate_pair_distinct'));
            }
            if (bccomp($this->string('rate')->toString(), '0', 12) <= 0
                || strlen(explode('.', $this->string('rate')->toString())[0]) > 11) {
                $validator->errors()->add('rate', __('messages.finance_rate_invalid'));
            }
            if ($this->date('rate_date')->toDateString() > CarbonImmutable::now($this->user()->calendarTimezone())->toDateString()) {
                $validator->errors()->add('rate_date', __('messages.finance_future_date'));
            }
        }];
    }
}
