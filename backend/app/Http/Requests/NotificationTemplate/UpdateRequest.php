<?php

namespace App\Http\Requests\NotificationTemplate;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'code' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('notification_templates', 'code')->ignore($this->route('id')),
            ],
            'content' => ['sometimes', 'string'],
            'variables' => ['nullable', 'array'],
            'example' => ['nullable', 'string'],
            'status' => ['sometimes', 'integer', Rule::in([0, 1])],
        ];
    }
}
