<?php

namespace Plugins\CloudDeploy\Deployers\Ratpanel;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 耗子面板（RatPanel）异常脱敏（site / console 两端点共用）。
 *
 * 泄露面：HMAC 签名 + accessTokenId 放 `Authorization` / `X-Timestamp` 头（非 URL、非 body），accessToken
 * 密钥仅作 HMAC 计算输入、绝不外发，故响应体错误码/描述不含凭证，安全。但底层 Guzzle 网络异常 message
 * 可能含请求 URL → 只暴露类名。
 *   - RatpanelApiException（结构化 API 错误，HTTP 非 2xx / msg≠success 归一）：取错误码 + 自带 msg 拼安全文案。
 *   - GuzzleException / 其余未知 Throwable：只暴露类名，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class RatpanelErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof RatpanelApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'RatPanelError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '耗子面板接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return '耗子面板调用失败: '.class_basename($e);
        }

        return '耗子面板调用失败: '.class_basename($e);
    }
}
