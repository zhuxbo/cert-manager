<?php

namespace App\Http\Requests\Delegation;

use App\Http\Requests\BaseRequest;

class StoreRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
            'zone' => 'required|string|max:255',
            // 委托创建按 CA 选择，内部经 ca_map 派生 prefix + zone；
            // 未知 ca 走 default(_dnsauth)，故宽松校验即可（不限定枚举）
            'ca' => 'required|string|max:50',
        ];
    }
}
