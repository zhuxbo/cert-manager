<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [];
        $defaults = config('notification.user_default_preferences', []);

        foreach ($defaults as $code => $_) {
            $rules[$code] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
