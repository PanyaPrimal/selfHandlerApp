<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\Models\FinanceAccount;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreFinanceAccountRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'type', 'currency', 'opening_balance', 'opening_date', 'opening_note'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(FinanceAccount::TYPES)],
            'currency' => ['required', Rule::exists('currencies', 'code')->where('is_active', true)],
            'opening_balance' => ['sometimes', 'string', 'max:32'],
            'opening_date' => ['sometimes', 'date_format:Y-m-d'],
            'opening_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (trim($this->string('name')->toString()) === '') {
                $validator->errors()->add('name', __('validation.required', ['attribute' => 'name']));
            }
            try {
                if ($this->has('opening_balance')) {
                    Money::of($this->string('opening_balance')->toString(), $this->string('currency')->toString());
                }
            } catch (InvalidArgumentException) {
                $validator->errors()->add('opening_balance', __('messages.finance_money_invalid'));
            }
            if ($this->has('opening_date') && $this->user()
                && $this->date('opening_date')->toDateString() > CarbonImmutable::now($this->user()->calendarTimezone())->toDateString()) {
                $validator->errors()->add('opening_date', __('messages.finance_future_date'));
            }
        }];
    }
}
