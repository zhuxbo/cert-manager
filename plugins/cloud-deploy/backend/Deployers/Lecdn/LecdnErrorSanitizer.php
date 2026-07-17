<?php

namespace Plugins\CloudDeploy\Deployers\Lecdn;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * LeCDN 异常脱敏。
 *
 * 泄露面：username/password 仅出现在登录请求 `POST /auth/login` 的 body，token 进 `Authorization: Bearer` 头
 * （业务接口非 URL/body 带凭证）。故威胁是底层 Guzzle 网络异常的 message/trace 可能带出请求 URI 或登录 body
 * （连带账号密码）→ 只暴露类名。
 *   - LecdnApiException（结构化 API 错误，HTTP 非 2xx / code≠200 归一）：取 code + 描述（响应体，不含凭证）。
 *   - GuzzleException / 其余未知 Throwable：只暴露类名，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class LecdnErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof LecdnApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'LeCDNError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'LeCDN 接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return 'LeCDN 调用失败: '.class_basename($e);
        }

        return 'LeCDN 调用失败: '.class_basename($e);
    }
}
