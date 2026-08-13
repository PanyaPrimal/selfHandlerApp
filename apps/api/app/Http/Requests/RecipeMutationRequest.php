<?php

namespace App\Http\Requests;

class RecipeMutationRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160'],
            'description' => [$this->isMethod('post') ? 'present' : 'sometimes', 'nullable', 'string', 'max:1000'],
            'components' => [$required, 'array', 'min:1', 'max:100'],
            'components.*' => ['array:food_item_id,quantity_grams'],
            'components.*.food_item_id' => ['required', 'integer', 'distinct'],
            'components.*.quantity_grams' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }

    protected function allowedKeys(): array
    {
        return $this->isMethod('post')
            ? ['name', 'description', 'components']
            : ['name', 'description', 'components', 'is_archived'];
    }

    protected function nestedAllowedKeys(): array
    {
        return ['components' => ['food_item_id', 'quantity_grams']];
    }
}
