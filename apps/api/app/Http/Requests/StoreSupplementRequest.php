<?php

namespace App\Http\Requests;

use App\Models\Supplement;
use App\ValueObjects\SupplementQuantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreSupplementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(Supplement::CATEGORIES)],
            'form' => ['required', Rule::in(Supplement::FORMS)],
            'stock_unit' => ['required', Rule::in(SupplementQuantity::STOCK_UNITS)],
            'preferred_display_unit' => ['required', Rule::in(SupplementQuantity::DISPLAY_UNITS)],
            'usual_dose_quantity' => ['required', 'string', 'max:32'],
            'package_quantity' => ['present', 'nullable', 'string', 'max:32'],
            'restock_lead_days' => ['required', 'integer', 'between:0,90'],
            'note' => ['present', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                $dose = SupplementQuantity::fromDisplay(
                    $this->string('usual_dose_quantity')->toString(),
                    $this->string('preferred_display_unit')->toString(),
                    $this->string('stock_unit')->toString(),
                )->canonical();
                if (bccomp($dose, '0', 6) <= 0) {
                    throw new InvalidArgumentException;
                }
                if ($this->input('package_quantity') !== null) {
                    $package = SupplementQuantity::fromDisplay(
                        (string) $this->input('package_quantity'),
                        $this->string('preferred_display_unit')->toString(),
                        $this->string('stock_unit')->toString(),
                    )->canonical();
                    if (bccomp($package, '0', 6) <= 0) {
                        throw new InvalidArgumentException;
                    }
                }
            } catch (InvalidArgumentException) {
                $validator->errors()->add('preferred_display_unit', __('messages.supplement_unit_incompatible'));
            }
        }];
    }
}
