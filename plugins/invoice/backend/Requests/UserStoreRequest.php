<?php

namespace Plugins\Invoice\Requests;

use App\Http\Requests\BaseRequest;

class UserStoreRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:1',
            'organization' => 'required|string|max:100',
            'taxation' => 'required|string|max:100',
            'remark' => 'nullable|string|max:500',
            'email' => 'required|string|email|max:100',
        ];
    }
}
