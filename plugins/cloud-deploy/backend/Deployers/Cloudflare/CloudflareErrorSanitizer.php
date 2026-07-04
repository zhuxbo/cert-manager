<?php

namespace Plugins\CloudDeploy\Deployers\Cloudflare;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Cloudflare REST 异常脱敏（共用于所有 Cloudflare deployer）。
 *
 * 泄露面：Cloudflare Bearer Token 放 Authorization 请求头（非 URL 查询串、非 body），故响应体里的
 * 错误码/描述不含 token，安全。但底层 Guzzle 网络异常 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Qiniu/Aliyun/Tencent sanitizer 对称）：
 *   - CloudflareApiException（结构化 API 错误，由 HTTP 非 2xx / success=false 归一）：取 Cloudflare
 *     错误码 + 自带描述（均来自响应体 errors）拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class CloudflareErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof CloudflareApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'CloudflareError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Cloudflare 接口返回错误';

            return "[$code] $msg";
        }

        return 'Cloudflare 调用失败: '.class_basename($e);
    }
}
