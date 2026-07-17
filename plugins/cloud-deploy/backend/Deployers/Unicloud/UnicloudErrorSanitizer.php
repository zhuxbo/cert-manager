<?php

namespace Plugins\CloudDeploy\Deployers\Unicloud;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * uniCloud REST 异常脱敏（共用于所有 uniCloud deployer）。
 *
 * 泄露面：uniCloud 走「账号 + 密码」登录（凭证在登录请求体、token 在请求头，非 URL 查询串），故响应体里
 * 的错误描述不含凭证，安全。但底层 Guzzle 网络异常 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Cloudflare/Vercel sanitizer 对称）：
 *   - UnicloudApiException（结构化 API 错误，由 success=false / ret!=0 / HTTP 非 2xx 归一）：取错误码 +
 *     自带描述（来自响应体 error 或 desc）拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class UnicloudErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof UnicloudApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'UniCloudError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'uniCloud 接口返回错误';

            return "[$code] $msg";
        }

        return 'uniCloud 调用失败: '.class_basename($e);
    }
}
