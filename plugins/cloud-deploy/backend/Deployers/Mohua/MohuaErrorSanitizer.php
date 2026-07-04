<?php

namespace Plugins\CloudDeploy\Deployers\Mohua;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 嘿华云 REST 异常脱敏（共用于所有嘿华云 deployer）。
 *
 * 泄露面：嘿华云走「账号 + API 密钥」登录换 JWT（凭证在登录请求体、token 在 JWT 请求头，非 URL 查询串），
 * 故响应体里的错误描述不含凭证，安全。但底层 Guzzle 网络异常 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Cloudflare/Vercel sanitizer 对称）：
 *   - MohuaApiException（结构化 API 错误，由 HTTP 非 2xx / status!=200 归一）：取 status + 自带描述
 *     （来自响应体 msg）拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class MohuaErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof MohuaApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'MohuaError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '嘿华云接口返回错误';

            return "[$code] $msg";
        }

        return '嘿华云调用失败: '.class_basename($e);
    }
}
