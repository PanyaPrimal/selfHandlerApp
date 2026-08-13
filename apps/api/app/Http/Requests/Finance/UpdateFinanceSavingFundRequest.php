<?php

namespace App\Http\Requests\Finance;

class UpdateFinanceSavingFundRequest extends StoreFinanceSavingFundRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'funding_account_id', 'category_id', 'target_amount',
            'deadline', 'rule', 'note', 'active', 'archived', 'spent'];
    }

    public function rules(): array
    {
        $rules = parent::rules();
        foreach ($rules as $field => &$fieldRules) {
            if (! in_array($field, $this->allowedKeys(), true) && ! str_starts_with($field, 'rule.')) {
                unset($rules[$field]);
            } elseif ($field !== 'rule' && ! str_starts_with($field, 'rule.')) {
                $fieldRules[0] = 'sometimes';
            }
        }
        $rules['rule'] = ['sometimes', 'array'];
        foreach (array_keys($rules) as $field) {
            if (str_starts_with($field, 'rule.')) {
                $rules[$field][0] = 'required_with:rule';
            }
        }
        $rules['active'] = ['sometimes', 'boolean'];
        $rules['archived'] = ['sometimes', 'boolean'];
        $rules['spent'] = ['sometimes', 'boolean'];

        return $rules;
    }
}
