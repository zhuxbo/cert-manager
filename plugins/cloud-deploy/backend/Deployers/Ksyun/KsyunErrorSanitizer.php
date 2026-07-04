<?php

namespace Plugins\CloudDeploy\Deployers\Ksyun;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 金山云 REST 异常脱敏（共用于所有金山云 deployer/uploader）。
 *
 * 泄露面：金山云开放 API 签名（HMAC-SHA256）以 Signature 字段塞进请求 query/body，**不回显**在响应里，
 * 故响应体的 Error.Code/Error.Message 不含 AccessKeyId/SecretAccessKey/Signature，安全。但底层 Guzzle
 * 网络异常（连接失败等）的 message 可能含请求 URL（GET 端点的 query 串里带 Accesskey/Signature）→ 仅暴露类名。
 *
 * 策略（与 Aliyun/Tencent/Qiniu/Baidu sanitizer 对称）：
 *   - KsyunApiException（结构化 API 错误，KsyunRestClient 由 HTTP 非 2xx / 响应体 Error 归一）：
 *     取金山云错误码 + 金山云自带描述（均来自响应体）拼安全文案。
 *   - 其余（本地/网络/未知 Throwable）：只给错误类名 + 通用文案，绝不回传 getMessage()。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御）。
 */
class KsyunErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof KsyunApiException) {
            // 结构化 API 错误：code + 描述取自响应体（Error.Code / Error.Message 或 HTTP 状态码），不含请求/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'KsyunError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '金山云接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle 失败 message 可能含含签名查询串的请求 URL）：只暴露类名
        return '金山云调用失败: '.class_basename($e);
    }
}
