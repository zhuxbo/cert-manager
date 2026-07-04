<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 火山引擎 REST 异常脱敏（共用于所有火山引擎 deployer/uploader）。
 *
 * 泄露面：火山引擎签名（HMAC-SHA256，AWS-V4 风格）把派生的 Authorization + 凭证派生量放在
 * **请求头**（Authorization / X-Date / X-Content-Sha256），AccessKeyId 仅出现在 Authorization 头的
 * Credential 段、SecretAccessKey 绝不上线。故响应体里的 Error.Code/Error.Message 不含 AK/SK，安全。
 * 但底层 Guzzle 网络异常（RequestException）的 message 可能含请求 URL（URL 无凭证，但出于最小暴露）→ 仅暴露类名。
 *
 * 策略（与 Aliyun/Tencent/Qiniu/Baidu sanitizer 对称）：
 *   - VolcApiException（结构化 API 错误，VolcRestClient 由 HTTP 非 2xx / 响应体 Error 归一）：
 *     取火山错误码 + 火山自带描述（均来自响应体）拼安全文案。
 *   - 其余（本地/网络/未知 Throwable）：只给错误类名 + 通用文案，绝不回传 getMessage()。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御）。
 */
class VolcErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof VolcApiException) {
            // 结构化 API 错误：code + 描述取自响应体（Error.Code / Error.Message 或 HTTP 状态码），不含请求/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'VolcError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '火山引擎接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle message 可能含请求 URL）：只暴露类名
        return '火山引擎调用失败: '.class_basename($e);
    }
}
