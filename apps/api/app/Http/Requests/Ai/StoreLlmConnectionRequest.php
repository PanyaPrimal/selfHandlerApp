<?php

namespace App\Http\Requests\Ai;

use App\Models\LlmConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLlmConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('llm_connections', 'name')->where(
                fn ($query) => $query->where('user_id', $this->user()->id),
            )],
            'provider' => ['required', 'string', Rule::in(LlmConnection::PROVIDERS)],
            'model' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            'api_key' => ['required', 'string', 'min:8', 'max:1000'],
            'parameters' => ['required', 'array:max_output_tokens'],
            'parameters.max_output_tokens' => [
                'required', 'integer', 'min:'.config('ai.minimum_max_output_tokens', 128),
                'max:'.config('ai.maximum_max_output_tokens', 2048),
            ],
        ];
    }
}
