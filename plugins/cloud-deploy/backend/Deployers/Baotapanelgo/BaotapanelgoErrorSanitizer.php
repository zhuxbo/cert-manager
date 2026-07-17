<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanelgo;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 宝塔面板（Windows Go 版）REST 异常脱敏（共用于所有 baotapanelgo deployer）。
 *
 * 泄露面同 Linux 宝塔：api_sk 仅用于本地算签名（经 request_token 表单字段提交），不出现在 URL/响应体。
 * 底层 Guzzle 网络异常 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与其它 sanitizer 对称）：
 *   - BaotapanelgoApiException：取 code + 自带 msg。
 *   - GuzzleException：只暴露类名。
 *   - 其余 Throwable：只给类名 + 通用文案。
 * 末尾过 CredentialScrubber 兜底。
 */
class BaotapanelgoErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof BaotapanelgoApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'BaotaError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '宝塔面板接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return '宝塔面板调用失败: '.class_basename($e);
        }

        return '宝塔面板调用失败: '.class_basename($e);
    }
}
