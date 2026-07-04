<?php

namespace Plugins\CloudDeploy\Deployers\Dogecloud;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 多吉云 REST 异常脱敏（共用于所有多吉云 deployer/uploader）。
 *
 * 泄露面：多吉云开放 API 签名（HMAC-SHA1）以 Authorization 请求头（TOKEN {accessKey}:{signature}）下发，
 * **不回显**在响应里，故响应体的 code/msg 不含 AccessKey/SecretKey/签名，安全。但底层 Guzzle 网络异常
 * （连接失败等）的 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Aliyun/Tencent/Ksyun/Baidu sanitizer 对称）：
 *   - DogecloudApiException（结构化 API 错误，DogecloudRestClient 由 HTTP 非 2xx / 响应体 code 归一）：
 *     取多吉云错误码 + 多吉云自带描述（均来自响应体）拼安全文案。
 *   - 其余（本地/网络/未知 Throwable）：只给错误类名 + 通用文案，绝不回传 getMessage()。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御）。
 */
class DogecloudErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof DogecloudApiException) {
            // 结构化 API 错误：code + 描述取自响应体（code / msg 或 HTTP 状态码），不含请求/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'DogecloudError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '多吉云接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle 失败 message 可能含请求 URL）：只暴露类名
        return '多吉云调用失败: '.class_basename($e);
    }
}
