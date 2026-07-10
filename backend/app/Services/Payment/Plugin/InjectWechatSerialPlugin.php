<?php

namespace App\Services\Payment\Plugin;

use Closure;
use Yansongda\Artful\Contract\PluginInterface;
use Yansongda\Artful\Rocket;

/**
 * 微信 v3 查单管线注入 Wechatpay-Serial 公钥头。
 *
 * vendor 的 QueryPlugin 用 setPayload() 整体重建 payload，会抹掉 StartPlugin
 * 从 params 合入的 _serial_no，导致后续 AddRadarPlugin 读不到、不发 Wechatpay-Serial
 * 头，商户后台「应答使用公钥比例」无法推进（下单走 mergePayload 不受影响）。
 *
 * 本插件放在 AddRadarPlugin 之前：params 全程不被重建、仍持有 _serial_no，
 * 从中回灌 payload，使 AddRadarPlugin 能设置 Wechatpay-Serial 头。
 * 未配公钥（params 无 _serial_no）时不做任何事，行为与切换前一致。
 */
class InjectWechatSerialPlugin implements PluginInterface
{
    public function assembly(Rocket $rocket, Closure $next): Rocket
    {
        $serialNo = $rocket->getParams()['_serial_no'] ?? null;

        if (! empty($serialNo)) {
            $rocket->mergePayload(['_serial_no' => $serialNo]);
        }

        return $next($rocket);
    }
}
