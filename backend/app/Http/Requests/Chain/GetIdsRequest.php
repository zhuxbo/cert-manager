<?php

namespace App\Http\Requests\Chain;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:chains,id');
    }
}
