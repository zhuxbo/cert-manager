<?php

namespace Plugins\CloudDeploy\Deployers\Rainyun;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 雨云 REST 异常脱敏（共用于所有雨云 deployer/uploader）。
 *
 * 泄露面：雨云 API Key 放 X-API-Key 请求头（非 URL 查询串、非 body），故响应体里的错误描述不含
 * 凭证，安全。但底层 Guzzle 网络异常 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Cloudflare/Vercel sanitizer 对称）：
 *   - RainyunApiException（结构化 API 错误，由 HTTP 非 2xx / code 异常归一）：取 code + 自带描述
 *     （来自响应体 message）拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class RainyunErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof RainyunApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'RainyunError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '雨云接口返回错误';

            return "[$code] $msg";
        }

        return '雨云调用失败: '.class_basename($e);
    }
}
