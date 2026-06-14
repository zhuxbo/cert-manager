<?php

namespace App\Http\Requests\UserLevel;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:user_levels,id');
    }
}
