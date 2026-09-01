<?php

namespace App\Http\Requests\Logs;

use App\Http\Requests\BaseRequest;

class LogsErrorRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'currentPage' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'created_at' => 'nullable|array|size:2',
            'created_at.*' => 'string|date_format:Y-m-d\TH:i:s.v\Z',
            'module' => 'nullable|string|max:100',
            'action' => 'nullable|string|max:100',
            'url' => 'nullable|string|max:500',
            'method' => 'nullable|string|max:10',
            'exception' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:100',
            'trace' => 'nullable|string',
            'status_code' => 'nullable|integer',
            'ip' => 'nullable|string|max:45',
        ];
    }
}
