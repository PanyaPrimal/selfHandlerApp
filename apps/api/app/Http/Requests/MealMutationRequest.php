<?php

namespace App\Http\Requests;

class MealMutationRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        return [
            'consumed_on' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['present', 'nullable', 'in:breakfast,lunch,dinner,snack,custom'],
            'consumed_at_local' => ['present', 'nullable', 'date_format:H:i'],
            'note' => ['present', 'nullable', 'string', 'max:1000'],
            'submission_key' => [$this->isMethod('post') ? 'required' : 'prohibited', 'uuid'],
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*' => ['array:food_item_id,recipe_id,quantity'],
            'entries.*.food_item_id' => ['present', 'nullable', 'integer'],
            'entries.*.recipe_id' => ['present', 'nullable', 'integer'],
            'entries.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ];
    }

    protected function allowedKeys(): array
    {
        $keys = ['consumed_on', 'name', 'category', 'consumed_at_local', 'note', 'entries'];
        if ($this->isMethod('post')) {
            $keys[] = 'submission_key';
        }

        return $keys;
    }

    protected function nestedAllowedKeys(): array
    {
        return ['entries' => ['food_item_id', 'recipe_id', 'quantity']];
    }
}
