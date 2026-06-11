<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:organizations,id');
    }
}
