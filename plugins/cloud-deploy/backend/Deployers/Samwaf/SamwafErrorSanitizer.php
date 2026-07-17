<?php

namespace Plugins\CloudDeploy\Deployers\Samwaf;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * SamWaf 异常脱敏。
 *
 * 泄露面：API Key 放 `X-API-Key` 头（非 URL、非 body），故响应体错误码/描述不含凭证，安全。
 * 但底层 Guzzle 网络异常 message 可能含请求 URL → 只暴露类名。
 *   - SamwafApiException（结构化 API 错误，HTTP 非 2xx / code≠0 归一）：取 code + 自带 msg 拼安全文案。
 *   - GuzzleException / 其余未知 Throwable：只暴露类名，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class SamwafErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof SamwafApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'SamWafError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'SamWaf 接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return 'SamWaf 调用失败: '.class_basename($e);
        }

        return 'SamWaf 调用失败: '.class_basename($e);
    }
}
