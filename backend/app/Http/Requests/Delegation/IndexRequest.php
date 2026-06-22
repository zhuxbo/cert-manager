<?php

namespace App\Http\Requests\Delegation;

use App\Http\Requests\BaseRequest;
use App\Services\Delegation\CnameDelegationService;
use Illuminate\Validation\Rule;

class IndexRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'currentPage' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'quickSearch' => 'nullable|string|max:255',
            'user_id' => 'nullable|integer|min:1',
            'zone' => 'nullable|string|max:255',
            // 列表按已存储的 prefix 列筛选（记录无 ca 列，多个 CA 可映射同一 prefix）；
            // 白名单从 config 派生，避免硬编码漂移
            'prefix' => ['nullable', Rule::in(CnameDelegationService::supportedPrefixes())],
            'valid' => 'nullable|boolean',
        ];
    }
}
