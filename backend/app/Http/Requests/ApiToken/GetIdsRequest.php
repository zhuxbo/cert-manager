<?php

namespace App\Http\Requests\ApiToken;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:api_tokens,id');
    }
}
