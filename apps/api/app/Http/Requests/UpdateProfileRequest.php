<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $today = CarbonImmutable::now($this->user()->calendarTimezone())->startOfDay();

        return [
            'name' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            'locale' => ['required', Rule::in(config('selfhandler.profile.locales'))],
            'unit_system' => ['required', Rule::in(config('selfhandler.profile.unit_systems'))],
            'base_currency' => ['required', Rule::in(config('selfhandler.profile.currencies'))],
            'recommendation_tone' => ['required', Rule::in(config('selfhandler.profile.recommendation_tones'))],
            'bmr_formula' => ['required', Rule::in(config('selfhandler.profile.bmr_formulas'))],
            'date_of_birth' => [
                'present', 'nullable', 'date_format:Y-m-d',
                'before_or_equal:'.$today->toDateString(),
                'after_or_equal:'.$today->subYears(120)->toDateString(),
            ],
            'sex' => ['present', 'nullable', Rule::in(config('selfhandler.profile.sexes'))],
            'height_meters' => ['present', 'nullable', 'numeric', 'between:0.5,3'],
            'weight_grams' => ['present', 'nullable', 'integer', 'between:20000,500000'],
            'body_fat_percentage' => ['present', 'nullable', 'numeric', 'between:2,75'],
            'baseline_activity' => ['present', 'nullable', Rule::in(config('selfhandler.profile.baseline_activities'))],
            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allowed = [
                    'name', 'timezone', 'locale', 'unit_system', 'base_currency',
                    'recommendation_tone', 'bmr_formula', 'date_of_birth', 'sex',
                    'height_meters', 'weight_grams', 'body_fat_percentage', 'baseline_activity',
                    'id', 'user_id', 'email', 'password',
                ];

                foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                    $validator->errors()->add($field, __('messages.unsupported_field'));
                }

                if ($this->input('bmr_formula') === 'katch_mcardle' && $this->input('body_fat_percentage') === null) {
                    $validator->errors()->add(
                        'body_fat_percentage',
                        __('messages.body_fat_required'),
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }
}
