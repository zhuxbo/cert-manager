<?php

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Oracle Cloud（OCI）REST 异常脱敏（共用于所有 OCI deployer/uploader）。
 *
 * 泄露面：OCI 签名放 Authorization 请求头（无凭证明文，仅 RSA 签名值），私钥仅本地签名用，
 * 故响应体里的错误码/描述不含私钥，安全。但底层 Guzzle 网络异常 message 可能含请求 URL → 仅暴露类名。
 * 末尾过 CredentialScrubber 兜底扫 PEM 头防御。
 *
 * 策略（与其他 sanitizer 对称）：
 *   - OraclecloudApiException（结构化 API 错误）：取 OCI 错误码 + 自带描述拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 */
class OraclecloudErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof OraclecloudApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'OracleCloudError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Oracle Cloud 接口返回错误';

            return "[$code] $msg";
        }

        return 'Oracle Cloud 调用失败: '.class_basename($e);
    }
}
