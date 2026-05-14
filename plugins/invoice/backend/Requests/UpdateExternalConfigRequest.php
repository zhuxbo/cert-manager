<?php

namespace Plugins\Invoice\Requests;

use App\Http\Requests\BaseRequest;

class UpdateExternalConfigRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'allowed_ips' => 'nullable|string|max:500',
        ];
    }
}
