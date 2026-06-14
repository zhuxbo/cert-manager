<?php

namespace App\Http\Requests\Fund;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:funds,id');
    }
}
