<?php

namespace Plugins\CloudDeploy\Requests;

use App\Http\Requests\BaseRequest;

class IndexRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'currentPage' => 'sometimes|integer|min:1',
            'pageSize' => 'sometimes|integer|min:1|max:100',
            'quickSearch' => 'sometimes|string|max:100',
            'provider' => 'sometimes|string|max:30',
            'product' => 'sometimes|string|max:30',
            'enabled' => 'sometimes|boolean',
            'order_id' => 'sometimes|integer',
            // target 列表新增筛选
            'last_status' => 'sometimes|string|max:20', // 含保留值 unpushed → whereNull
            'keyword' => 'sometimes|string|max:100',     // 证书域名（cert.common_name）模糊
            'last_deployed_at_start' => 'sometimes|date',
            'last_deployed_at_end' => 'sometimes|date',
            'created_at_start' => 'sometimes|date',
            'created_at_end' => 'sometimes|date',
            // access 列表新增筛选（Access controller 消费；Target 不消费，无害）
            'name' => 'sometimes|string|max:100',
        ];
    }
}
