<?php

namespace App\Http\Requests\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FinanceSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->query()), ['from', 'to', 'as_of']) !== []) {
                $validator->errors()->add('request', __('messages.unknown_fields'));
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $timezone = $this->user()->calendarTimezone();
            $today = CarbonImmutable::now($timezone)->startOfDay();
            $from = CarbonImmutable::parse($this->input('from', $today->subDays(29)->toDateString()), $timezone)->startOfDay();
            $to = CarbonImmutable::parse($this->input('to', $today->toDateString()), $timezone)->startOfDay();
            $asOf = CarbonImmutable::parse($this->input('as_of', $today->toDateString()), $timezone)->startOfDay();
            if ($from->greaterThan($to) || $from->diffInDays($to) > 365
                || $to->greaterThan($today) || $asOf->greaterThan($today)) {
                $validator->errors()->add('from', __('messages.finance_range_invalid'));
            }
        }];
    }

    /** @return array{from:string,to:string,as_of:string} */
    public function period(): array
    {
        $today = CarbonImmutable::now($this->user()->calendarTimezone());

        return [
            'from' => (string) $this->input('from', $today->subDays(29)->toDateString()),
            'to' => (string) $this->input('to', $today->toDateString()),
            'as_of' => (string) $this->input('as_of', $today->toDateString()),
        ];
    }
}
