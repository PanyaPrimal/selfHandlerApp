<?php

namespace App\Http\Requests\Ai;

use App\Models\LlmConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLlmConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:80', Rule::unique('llm_connections', 'name')->where(
                fn ($query) => $query->where('user_id', $this->user()->id),
            )->ignore($this->route('connection'))],
            'provider' => ['sometimes', 'string', Rule::in(LlmConnection::PROVIDERS)],
            'model' => ['sometimes', 'string', 'max:160', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            'api_key' => ['sometimes', 'string', 'min:8', 'max:1000'],
            'parameters' => ['sometimes', 'array:max_output_tokens'],
            'parameters.max_output_tokens' => [
                'required_with:parameters', 'integer', 'min:'.config('ai.minimum_max_output_tokens', 128),
                'max:'.config('ai.maximum_max_output_tokens', 2048),
            ],
        ];
    }
}
