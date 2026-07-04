<?php

namespace Plugins\CloudDeploy\Deployers\Googlecloud;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Google Cloud REST 异常脱敏（共用于所有 GCP deployer/uploader）。
 *
 * 泄露面：OAuth2 access_token 放 Authorization 请求头（非 URL 查询串、非 body），故响应体里的
 * 错误状态/描述不含 token，安全。但底层 Guzzle 网络异常 message 可能含请求 URL → 仅暴露类名。
 * 特别地，**OAuth2 换 token 时的 service-account 私钥 / client_secret 绝不会出现在响应错误体**
 * （仅本地签名用），但仍过 CredentialScrubber 兜底扫 PEM 头防御。
 *
 * 策略（与其他 sanitizer 对称）：
 *   - GooglecloudApiException（结构化 API 错误）：取 GCP 错误状态 + 自带描述拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 */
class GooglecloudErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof GooglecloudApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'GoogleCloudError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Google Cloud 接口返回错误';

            return "[$code] $msg";
        }

        return 'Google Cloud 调用失败: '.class_basename($e);
    }
}
