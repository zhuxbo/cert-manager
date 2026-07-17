<?php

namespace Plugins\CloudDeploy\Requests;

use App\Http\Requests\BaseRequest;

class LogIndexRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'currentPage' => 'sometimes|integer|min:1',
            'pageSize' => 'sometimes|integer|min:1|max:100',
            'quickSearch' => 'sometimes|string|max:100',
            'order_id' => 'sometimes|integer',
            'target_id' => 'sometimes|integer',
            'status' => 'sometimes|string|in:success,failed',
            'is_final' => 'sometimes|boolean',
            'provider' => 'sometimes|string|max:30',
            'product' => 'sometimes|string|max:30',
            'trigger' => 'sometimes|string|in:auto,manual',
            'keyword' => 'sometimes|string|max:100', // resource_summary（部署资源域名快照）模糊
            'created_at_start' => 'sometimes|date',
            'created_at_end' => 'sometimes|date',
        ];
    }

    /**
     * 归一 is_final：前端经 qs 序列化 JS 布尔 true/false → 字符串 "true"/"false"，
     * 但 Laravel `boolean` 规则的 acceptable 仅 [true,false,0,1,'0','1']，不含 "true"/"false" → 会 422。
     * 这里把 "true"/"false"（任意大小写）转回 1/0，使前端无论传 1/0 还是 true/false 都通过校验。
     * 必须 parent::prepareForValidation() 以保留 BaseRequest 的 ids 逗号拆分。
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->has('is_final') && is_string($this->input('is_final'))) {
            $v = strtolower(trim($this->input('is_final')));
            if ($v === 'true' || $v === 'false') {
                $this->merge(['is_final' => $v === 'true' ? 1 : 0]);
            }
        }
    }
}
