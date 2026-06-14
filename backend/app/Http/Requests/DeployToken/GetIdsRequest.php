<?php

namespace App\Http\Requests\DeployToken;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:deploy_tokens,id');
    }
}
