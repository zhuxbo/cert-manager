<?php

namespace App\Services\Payment;

use Yansongda\Pay\Pay;

/**
 * Yansongda Pay 的薄包装，隔离静态调用，便于回调入口测试替换。
 */
class PaymentGateway
{
    public function alipay(): mixed
    {
        return Pay::alipay();
    }

    public function wechat(): mixed
    {
        return Pay::wechat();
    }
}
