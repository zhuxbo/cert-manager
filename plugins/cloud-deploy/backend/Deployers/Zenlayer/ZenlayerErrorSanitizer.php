<?php

namespace Plugins\CloudDeploy\Deployers\Zenlayer;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Zenlayer REST 异常脱敏（共用于所有 Zenlayer deployer/uploader）。
 *
 * 泄露面：Zenlayer ZC2-HMAC-SHA256 签名以 Authorization 请求头（Credential={keyId}, Signature={sig}）下发，
 * **不回显**在响应里，故响应体的 code/message 不含 SecretKeyId/SecretKeyPassword/签名，安全。但底层 Guzzle
 * 网络异常（连接失败等）的 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Aliyun/Tencent/Ksyun/Baidu sanitizer 对称）：
 *   - ZenlayerApiException（结构化 API 错误，ZenlayerRestClient 由 HTTP 非 2xx / 响应体 code 归一）：
 *     取 Zenlayer 错误码 + Zenlayer 自带描述（均来自响应体）拼安全文案。
 *   - 其余（本地/网络/未知 Throwable）：只给错误类名 + 通用文案。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御）。
 */
class ZenlayerErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof ZenlayerApiException) {
            // 结构化 API 错误：code + 描述取自响应体（code / message 或 HTTP 状态码），不含请求/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'ZenlayerError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Zenlayer 接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle 失败 message 可能含请求 URL）：只暴露类名
        return 'Zenlayer 调用失败: '.class_basename($e);
    }
}
