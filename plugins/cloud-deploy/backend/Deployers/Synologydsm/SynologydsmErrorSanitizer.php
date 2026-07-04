<?php

namespace Plugins\CloudDeploy\Deployers\Synologydsm;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 群晖 DSM WebAPI 异常脱敏（共用于所有群晖 deployer）。
 *
 * 泄露面：群晖 DSM 登录后 sid/SynoToken 随 URL 查询串（_sid / SynoToken）回送，底层 Guzzle 网络异常
 * message 可能含完整请求 URL（含 sid/SynoToken）+ 登录 body（account/passwd）→ 网络类异常仅暴露类名。
 * 结构化业务错误仅含数字 code（无入参回显），安全。
 *
 * 策略（与 Upyun/Cloudflare sanitizer 对称）：
 *   - SynologydsmApiException（结构化 API 错误，由 HTTP 非 2xx / success=false 归一）：取错误码 +
 *     内置可读描述（来自 code 映射，无凭证）拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class SynologydsmErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof SynologydsmApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'SynologyDSMError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '群晖 DSM 接口返回错误';

            return "[$code] $msg";
        }

        return '群晖 DSM 调用失败: '.class_basename($e);
    }
}
