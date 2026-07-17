<?php

namespace App\Http\Requests\ProductPrice;

use App\Http\Requests\BaseRequest;

class StoreRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'level_code' => 'required|string|min:3|max:20|exists:user_levels,code',
            'period' => 'required|integer|min:1',
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'price' => 'required|numeric|min:0',
            'alternative_standard_price' => 'nullable|numeric|min:0',
            'alternative_wildcard_price' => 'nullable|numeric|min:0',
        ];
    }
}
