<?php

namespace App\Http\Requests\Notification;

use App\Http\Requests\BaseRequest;

class SendTestRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'notifiable_type' => ['required', 'string', 'max:255'],
            'notifiable_id' => ['required', 'integer'],
            'template_type' => ['required', 'string', 'max:100'],
            'data' => ['nullable', 'array'],
        ];
    }
}
