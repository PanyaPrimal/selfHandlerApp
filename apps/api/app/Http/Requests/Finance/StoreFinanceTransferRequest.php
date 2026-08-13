<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreFinanceTransferRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return [
            'idempotency_key', 'source_account_id', 'destination_account_id', 'source_amount',
            'destination_amount', 'occurred_on', 'note', 'tag',
        ];
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'source_account_id' => ['required', 'integer', 'min:1'],
            'destination_account_id' => ['required', 'integer', 'min:1'],
            'source_amount' => ['required', 'string', 'max:32'],
            'destination_amount' => ['required', 'string', 'max:32'],
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
            foreach (['source_amount', 'destination_amount'] as $field) {
                try {
                    $money = Money::of($this->string($field)->toString(), 'UAH');
                    if (bccomp($money->amount(), '0', 4) <= 0) {
                        throw new InvalidArgumentException;
                    }
                } catch (InvalidArgumentException) {
                    $validator->errors()->add($field, __('messages.finance_positive_money'));
                }
            }
            if ($this->input('source_account_id') === $this->input('destination_account_id')) {
                $validator->errors()->add('destination_account_id', __('messages.finance_transfer_accounts_distinct'));
            }
            if ($this->date('occurred_on')->toDateString() > CarbonImmutable::now($this->user()->calendarTimezone())->toDateString()) {
                $validator->errors()->add('occurred_on', __('messages.finance_future_date'));
            }
        }];
    }
}
