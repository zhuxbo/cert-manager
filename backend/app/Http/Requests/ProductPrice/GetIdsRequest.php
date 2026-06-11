<?php

namespace App\Http\Requests\ProductPrice;

use App\Http\Requests\BaseRequest;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return $this->idsRules('integer|exists:product_prices,id');
    }
}
