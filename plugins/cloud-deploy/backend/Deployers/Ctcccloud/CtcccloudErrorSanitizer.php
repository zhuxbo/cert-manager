<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 天翼云 REST 异常脱敏（共用于所有天翼云 deployer/uploader）。
 *
 * 泄露面：天翼云 EOP 签名（HMAC-SHA256）以 eop-authorization 头塞进请求，**不回显**在响应里，
 * 故响应体的 statusCode/error/message 不含 AccessKeyId/SecretAccessKey/签名，安全。但底层 Guzzle
 * 网络异常（连接失败等）的 message 可能含请求 URL —— 天翼云 URL 不带凭证查询串（凭证在头），但稳妥起见
 * 仍只暴露类名，不回传 getMessage()。
 *
 * 策略（与 Aliyun/Tencent/Ksyun/Baidu sanitizer 对称）：
 *   - CtcccloudApiException（结构化 API 错误，CtcccloudRestClient 由 HTTP 非 2xx / 响应体 statusCode·error 归一）：
 *     取天翼云错误码 + 天翼云自带描述（均来自响应体）拼安全文案。
 *   - 其余（本地/网络/未知 Throwable）：只给错误类名 + 通用文案，绝不回传 getMessage()。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御）。
 */
class CtcccloudErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof CtcccloudApiException) {
            // 结构化 API 错误：code + 描述取自响应体（statusCode/error 与 message/errorMessage），不含请求/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'CtcccloudError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '天翼云接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle 失败 message 可能含请求 URL）：只暴露类名
        return '天翼云调用失败: '.class_basename($e);
    }
}
