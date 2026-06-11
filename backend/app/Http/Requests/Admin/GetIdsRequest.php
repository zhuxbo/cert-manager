<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:admins,id');
    }
}
