<?php

namespace App\Http\Requests\ApiToken;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'ids' => 'required|array|max:'.config('batch.max_ids'),
            'ids.*' => 'integer|exists:api_tokens,id',
        ];
    }
}
