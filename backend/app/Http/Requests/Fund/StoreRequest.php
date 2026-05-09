<?php

namespace App\Http\Requests\Fund;

use App\Http\Requests\BaseRequest;

class StoreRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|min:0',
            // 仅允许 addfunds（手工充值）/ deduct（手工扣款）；
            // refunds/reverse 是状态转换，必须走 fund/refunds/{id} 与 fund/reverse/{id}
            // UPDATE 同行接口，不能 INSERT 新行（否则会与 funds(pay_method, pay_sn) 唯一索引冲突）。
            'type' => 'required|string|in:addfunds,deduct',
            'pay_method' => 'required|string',
            'pay_sn' => 'nullable|string',
            'remark' => 'nullable|string|max:500',
            'status' => 'required|integer|in:0,1,2',
        ];
    }
}
