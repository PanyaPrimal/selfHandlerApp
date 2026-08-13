<?php

namespace App\Http\Requests;

use App\Models\Attachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attachable_type' => ['required', 'string', Rule::in(array_keys(Attachment::parentClasses()))],
            'attachable_id' => ['required', 'integer', 'min:1'],
            'upload_key' => ['required', 'string', 'between:1,100'],
            'file' => ['required', 'file', 'max:'.(int) ceil(config('attachments.max_source_bytes') / 1024)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = ['attachable_type', 'attachable_id', 'upload_key'];
            $unknownQuery = array_diff(array_keys($this->query()), $allowed);
            $unknownBody = array_diff(array_keys($this->request->all()), []);
            if ($unknownQuery !== [] || $unknownBody !== []) {
                $validator->errors()->add('request', __('messages.unknown_fields'));
            }
        });
    }

    /** @return array{attachable_type: string, attachable_id: int, upload_key: string} */
    public function metadata(): array
    {
        $data = $this->safe()->only(['attachable_type', 'attachable_id', 'upload_key']);

        return [
            'attachable_type' => $data['attachable_type'],
            'attachable_id' => (int) $data['attachable_id'],
            'upload_key' => trim($data['upload_key']),
        ];
    }
}
