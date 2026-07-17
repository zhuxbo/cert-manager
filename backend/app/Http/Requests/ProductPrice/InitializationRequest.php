<?php

declare(strict_types=1);

namespace App\Http\Requests\ProductPrice;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class InitializationRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'levels' => ['required', 'array', 'min:1'],
            'levels.*.code' => ['required', 'string', 'distinct', 'exists:user_levels,code'],
            'levels.*.cost_rate' => ['required', 'string', 'regex:/^(?:[1-9]|[1-9]\d)(?:\.\d{1,4})?$/'],
            'precision' => ['required', 'integer', Rule::in([0, 1, 2])],
            'force' => ['required', 'boolean'],
            'sync_cost_rates' => ['required', 'boolean'],
            'preview' => ['required', 'boolean'],
            'preview_token' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('preview'), [false, 0, '0'], true)),
                'nullable',
                'string',
            ],
        ];
    }
}
