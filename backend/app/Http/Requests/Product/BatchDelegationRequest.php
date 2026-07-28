<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\BaseRequest;

class BatchDelegationRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            ...$this->idsRules('integer|exists:products,id'),
            'enabled' => 'required|boolean',
        ];
    }
}
