<?php

namespace Plugins\CloudDeploy\Deployers\Qingcloud;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 青云 IaaS REST 异常脱敏（共用于所有青云 deployer/uploader）。
 *
 * 泄露面：青云 QY 签名（HMAC-SHA256）以 signature 字段塞进请求 query（GET）/ form（POST），**不回显**在
 * 响应里，故响应体的 ret_code/message 不含 access_key_id/secret_access_key/signature，安全。但底层 Guzzle
 * 网络异常（连接失败等）的 message 可能含请求 URL（GET 端点的查询串里带 access_key_id/signature）→ 仅暴露类名。
 *
 * 策略（与 Aliyun/Tencent/Ksyun/Baidu sanitizer 对称）：
 *   - QingcloudApiException（结构化 API 错误，QingcloudRestClient 由 HTTP 非 2xx / 响应体 ret_code 归一）：
 *     取青云错误码 + 青云自带描述（均来自响应体）拼安全文案。
 *   - 其余（本地/网络/未知 Throwable）：只给错误类名 + 通用文案。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御）。
 */
class QingcloudErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof QingcloudApiException) {
            // 结构化 API 错误：code + 描述取自响应体（ret_code / message 或 HTTP 状态码），不含请求/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'QingcloudError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '青云接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle 失败 message 可能含含签名查询串的请求 URL）：只暴露类名
        return '青云调用失败: '.class_basename($e);
    }
}
