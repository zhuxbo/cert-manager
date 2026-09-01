<?php

namespace App\Http\Requests\Logs;

use App\Http\Requests\BaseRequest;

class LogsApiRequest extends BaseRequest
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
            'params' => 'nullable|string',
            'response' => 'nullable|string',
            'status_code' => 'nullable|integer',
            'status' => 'nullable|integer|in:0,1',
            'user_id' => 'nullable|integer',
            'version' => 'nullable|string|max:50',
            'ip' => 'nullable|string|max:45',
        ];
    }
}
