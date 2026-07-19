<?php

namespace App\Http\Requests\AutoDeployReport;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'currentPage' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'quickSearch' => 'nullable|string|max:255',
            'order_id' => 'nullable|integer|min:1',
            'user_id' => 'nullable|integer|min:1',
            'status' => ['nullable', Rule::in(['success', 'failure'])],
            'ip' => 'nullable|string|max:100',
            'time' => 'nullable|array|size:2',
            'time.*' => 'string|date_format:Y-m-d\TH:i:s.v\Z',
        ];
    }
}
