<?php

namespace Plugins\Invoice\Requests;

use App\Http\Requests\BaseRequest;

class StoreRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|min:1',
            'organization' => 'required|string|max:100',
            'taxation' => 'required|string|max:100',
            'remark' => 'nullable|string|max:500',
            'email' => 'required|string|email|max:100',
            'status' => 'integer|in:0,1,2',
        ];
    }
}
