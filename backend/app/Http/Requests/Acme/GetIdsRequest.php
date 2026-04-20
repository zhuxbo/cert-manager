<?php

namespace App\Http\Requests\Acme;

use App\Http\Requests\BaseRequest;
use App\Models\Acme;

class GetIdsRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ];
    }

    /**
     * 验证后过滤掉不存在的 id（UserScope 已自动限制当前用户范围）
     */
    protected function passedValidation(): void
    {
        $ids = $this->input('ids', []);
        $existingIds = Acme::whereIn('id', $ids)->pluck('id')->toArray();
        $this->merge(['ids' => $existingIds]);
    }
}
