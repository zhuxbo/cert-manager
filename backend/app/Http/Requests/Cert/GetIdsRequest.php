<?php

namespace App\Http\Requests\Cert;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:certs,id');
    }
}
