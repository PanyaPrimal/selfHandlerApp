<?php

namespace App\Http\Requests;

use App\Models\Supplement;
use App\Models\SupplementStockMovement;
use App\ValueObjects\SupplementQuantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreSupplementStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(SupplementStockMovement::KINDS)],
            'quantity' => ['required', 'string', 'max:32'],
            'display_unit' => ['required', Rule::in(SupplementQuantity::DISPLAY_UNITS)],
            'effective_on' => ['required', 'date_format:Y-m-d',
                'before_or_equal:'.now($this->user()?->calendarTimezone() ?? 'UTC')->toDateString()],
            'reason' => ['present', 'nullable', 'string', 'max:500'],
            'note' => ['present', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }
            if ($this->input('kind') === SupplementStockMovement::KIND_CORRECTION
                && blank($this->input('reason'))) {
                $validator->errors()->add('reason', __('messages.supplement_stock_correction_reason'));
            }
            /** @var Supplement|null $supplement */
            $supplement = $this->route('supplement');
            if (! $supplement || ! is_string($this->input('quantity'))
                || ! is_string($this->input('display_unit'))) {
                return;
            }
            try {
                $quantity = SupplementQuantity::fromDisplay(
                    $this->input('quantity'), $this->input('display_unit'), $supplement->stock_unit,
                )->canonical();
                $valid = $this->input('kind') === SupplementStockMovement::KIND_RESTOCK
                    ? bccomp($quantity, '0', 6) > 0
                    : bccomp($quantity, '0', 6) !== 0;
                if (! $valid) {
                    throw new InvalidArgumentException;
                }
            } catch (InvalidArgumentException) {
                $validator->errors()->add('quantity', __('messages.supplement_stock_quantity'));
            }
        }];
    }
}
