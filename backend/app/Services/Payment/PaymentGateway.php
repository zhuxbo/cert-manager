<?php

namespace App\Services\Payment;

use App\Services\Payment\Plugin\InjectWechatSerialPlugin;
use Yansongda\Pay\Pay;
use Yansongda\Pay\Plugin\Wechat\AddRadarPlugin;
use Yansongda\Pay\Shortcut\Wechat\QueryShortcut;

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

    /**
     * 微信 v3 查单（注入 Wechatpay-Serial 公钥头）。
     *
     * 直接 ->wechat()->query() 会因 vendor QueryPlugin 的 setPayload 抹掉 _serial_no
     * 而不发头（见 InjectWechatSerialPlugin）。此处取 QueryShortcut 的原始插件列表
     * （跟随 vendor 升级自动漂移），在 AddRadarPlugin 前插入 InjectWechatSerialPlugin
     * 把 _serial_no 回灌 payload，再走 pay() 执行。
     *
     * 入参 $order 需已按调用方 gate（publicKeyId + publicKey 俱全）合入 _serial_no；
     * 未合入时插件自动跳过，等价于普通查单。
     */
    public function wechatQuery(array $order): mixed
    {
        $plugins = (new QueryShortcut)->getPlugins($order);

        $pos = array_search(AddRadarPlugin::class, $plugins, true);
        if ($pos !== false) {
            array_splice($plugins, $pos, 0, [InjectWechatSerialPlugin::class]);
        }

        return Pay::wechat()->pay($plugins, $order);
    }
}
