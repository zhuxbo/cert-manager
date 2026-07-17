<?php

namespace Plugins\CloudDeploy\Deployers\Netlify;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Netlify REST 异常脱敏（共用于所有 Netlify deployer）。
 *
 * 泄露面：Netlify API Token 放 Authorization 请求头（非 URL 查询串、非 body），故响应体里的
 * 错误码/描述不含 token，安全。但底层 Guzzle 网络异常 message 可能含请求 URL（PEM 走查询串）→ 仅暴露类名。
 *
 * 策略（与 Cloudflare/Digitalocean sanitizer 对称）：
 *   - NetlifyApiException（结构化 API 错误，由 HTTP 非 2xx / 非零 code 归一）：取 Netlify 错误码 + 自带描述
 *     拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class NetlifyErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof NetlifyApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'NetlifyError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Netlify 接口返回错误';

            return "[$code] $msg";
        }

        return 'Netlify 调用失败: '.class_basename($e);
    }
}
