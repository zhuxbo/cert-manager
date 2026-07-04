<?php

namespace Plugins\CloudDeploy\Deployers\Baotawaf;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 堡塔云 WAF REST 异常脱敏（共用于所有 baotawaf deployer）。
 *
 * 泄露面：堡塔 WAF 走 api_sk + waf_request_time MD5 签名（apiKey 仅用于本地算签名、经 waf_request_token
 * 请求头提交，不出现在 URL 查询串/响应体），故响应体里的 code 不含 apiKey，安全。但底层 Guzzle 网络异常
 * message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与其它 sanitizer 对称）：
 *   - BaotawafApiException：取 code + 描述。
 *   - GuzzleException：只暴露类名。
 *   - 其余 Throwable：只给类名 + 通用文案。
 * 末尾过 CredentialScrubber 兜底。
 */
class BaotawafErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof BaotawafApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'BaotaWafError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '堡塔云 WAF 接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return '堡塔云 WAF 调用失败: '.class_basename($e);
        }

        return '堡塔云 WAF 调用失败: '.class_basename($e);
    }
}
