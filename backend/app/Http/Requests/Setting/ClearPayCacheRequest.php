<?php

namespace App\Http\Requests\Setting;

use App\Http\Requests\BaseRequest;
use App\Services\Payment\PayConfigCache;
use Illuminate\Validation\Rule;

class ClearPayCacheRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(PayConfigCache::TYPES)],
        ];
    }
}
