<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\BaseRequest;

class IndexRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'currentPage' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'quickSearch' => 'nullable|string|max:100',
            'statusSet' => 'nullable|string|in:all,activating,archived',
            'id' => 'nullable|integer|min:1',
            'period' => 'nullable|integer|in:1,3,6,12,24,36,48,60,72,84,96,108,120',
            'amount' => 'nullable|array|size:1,2',
            'amount.*' => 'numeric',
            'created_at' => 'nullable|array|size:2',
            'created_at.*' => 'string|date_format:Y-m-d\TH:i:s.v\Z',
            'user_id' => 'nullable|integer|min:1',
            'username' => 'nullable|string|max:20',
            'product_name' => 'nullable|string|max:50',
            'domain' => 'nullable|string|max:255',
            'channel' => 'nullable|string|in:admin,api,web,deploy,auto',
            'action' => 'nullable|string|in:new,renew,reissue',
            'expires_at' => 'nullable|array|size:2',
            'expires_at.*' => 'string|date_format:Y-m-d\TH:i:s.v\Z',
            'status' => 'nullable|in:unpaid,pending,processing,active,approving,cancelling,failed,cancelled,renewed,expired,revoked',
            'sort_prop' => 'nullable|string|in:period_till,expires_at',
            'sort_order' => 'nullable|string|in:asc,desc',
        ];
    }
}
