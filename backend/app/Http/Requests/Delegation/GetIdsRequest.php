<?php

namespace App\Http\Requests\Delegation;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:cname_delegations,id');
    }
}
