<?php

namespace Plugins\CloudDeploy\Deployers\Safeline;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 雷池 WAF（SafeLine）异常脱敏。
 *
 * 泄露面：API 令牌放 `X-SLCE-API-TOKEN` 头（非 URL、非 body），故响应体错误码/描述不含凭证，安全。
 * 但底层 Guzzle 网络异常 message 可能含请求 URL → 只暴露类名。
 *   - SafelineApiException（结构化 API 错误，HTTP 非 2xx / err 非空归一）：取 err 码 + 自带 msg 拼安全文案。
 *   - GuzzleException / 其余未知 Throwable：只暴露类名，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class SafelineErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof SafelineApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'SafeLineError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '雷池 WAF 接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return '雷池 WAF 调用失败: '.class_basename($e);
        }

        return '雷池 WAF 调用失败: '.class_basename($e);
    }
}
