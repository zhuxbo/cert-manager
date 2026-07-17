<?php

namespace Plugins\CloudDeploy\Deployers\Gcore;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Gcore REST 异常脱敏（共用于所有 Gcore deployer）。
 *
 * 泄露面：Gcore API Token 放 Authorization: APIKey 请求头（非 URL 查询串、非 body），故响应体里的
 * 错误描述不含 token，安全。但底层 Guzzle 网络异常 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Cloudflare/Digitalocean sanitizer 对称）：
 *   - GcoreApiException（结构化 API 错误，由 HTTP 非 2xx 归一）：取 HTTP 状态码 + 响应体描述拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class GcoreErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof GcoreApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'GcoreError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Gcore 接口返回错误';

            return "[$code] $msg";
        }

        return 'Gcore 调用失败: '.class_basename($e);
    }
}
