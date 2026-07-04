<?php

namespace Plugins\CloudDeploy\Deployers\Apisix;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * APISIX Admin API 异常脱敏（共用于所有 APISIX deployer）。
 *
 * 泄露面：APISIX 走「X-API-KEY」请求头鉴权（非 URL 查询串、非 body），故响应体里的错误描述不含
 * 凭证，安全。但底层 Guzzle 网络异常 message 可能含请求 URL（自建服务地址）→ 仅暴露类名。
 *
 * 策略（与 Cloudflare/Kong sanitizer 对称）：
 *   - ApisixApiException（结构化 API 错误，由 HTTP 非 2xx 归一）：取 HTTP 状态码 + 自带描述
 *     （来自响应体 error_msg）拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class ApisixErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof ApisixApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'ApisixError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'APISIX 接口返回错误';

            return "[$code] $msg";
        }

        return 'APISIX 调用失败: '.class_basename($e);
    }
}
