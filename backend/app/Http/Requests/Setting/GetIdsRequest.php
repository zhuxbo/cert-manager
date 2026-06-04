<?php

namespace App\Http\Requests\Setting;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => 'required|array|max:'.config('batch.max_ids'),
            'ids.*' => 'integer|exists:settings,id',
        ];
    }
}
