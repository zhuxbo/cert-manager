<?php

namespace Plugins\CloudDeploy\Deployers\Vercel;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Vercel REST 异常脱敏（共用于所有 Vercel deployer）。
 *
 * 泄露面：Vercel API Access Token 放 Authorization 请求头（非 URL 查询串、非 body），故响应体里的
 * 错误码/描述不含 token，安全。但底层 Guzzle 网络异常 message 可能含请求 URL（含 teamId 查询参）→ 仅暴露类名。
 *
 * 策略（与 Cloudflare/Digitalocean sanitizer 对称）：
 *   - VercelApiException（结构化 API 错误，由 HTTP 非 2xx / error.code 归一）：取 Vercel 错误码 + 自带描述
 *     （均来自响应体 error）拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class VercelErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof VercelApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'VercelError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Vercel 接口返回错误';

            return "[$code] $msg";
        }

        return 'Vercel 调用失败: '.class_basename($e);
    }
}
