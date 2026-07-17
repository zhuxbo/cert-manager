<?php

namespace Plugins\CloudDeploy\Deployers\Flexcdn;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * FlexCDN 异常脱敏。
 *
 * 泄露面：accessKeyId/accessKey 仅出现在登录请求 `POST /APIAccessTokenService/getAPIAccessToken` 的 body，
 * token 进 `X-Cloud-Access-Token` 头（业务接口非 URL/body 带凭证）。故威胁是底层 Guzzle 网络异常的
 * message/trace 可能带出请求 URI 或登录 body（连带 accessKey）→ 只暴露类名。
 *   - FlexcdnApiException（结构化 API 错误，HTTP 非 2xx / code≠200 归一）：取 code + message（响应体，不含凭证）。
 *   - GuzzleException / 其余未知 Throwable：只暴露类名，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class FlexcdnErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof FlexcdnApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'FlexCDNError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'FlexCDN 接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return 'FlexCDN 调用失败: '.class_basename($e);
        }

        return 'FlexCDN 调用失败: '.class_basename($e);
    }
}
