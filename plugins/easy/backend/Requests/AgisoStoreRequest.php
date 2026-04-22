<?php

namespace Plugins\Easy\Requests;

use App\Http\Requests\BaseRequest;

class AgisoStoreRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'product_code' => 'required|string',
            'period' => 'required|integer',
            'amount' => 'numeric|min:0',
            'pay_method' => 'string|in:other,alipay,wechat,credit,gift,taobao,pinduoduo,jingdong,douyin',
            'count' => 'integer|min:1|max:100',
        ];
    }
}
