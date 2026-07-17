<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 华为云 REST 异常脱敏（共用于所有华为云 deployer/uploader）。
 *
 * 泄露面：华为云 SDK-HMAC-SHA256 签名写入 **Authorization 请求头**（AK 以 `Access=` 明文进头、SK 仅参与 HMAC、
 * Signature 也在头里，均非 URL 查询串、非 body），故响应体里的 error_code/error_msg 不含 SecretAccessKey/Signature，
 * 安全。但底层 Guzzle 网络异常（连接失败/超时等）的 message 可能含请求 URL（host/path）→ URL 本身无凭证，但仍仅暴露类名兜底。
 *
 * 策略（与 Aliyun/Tencent/Qiniu/Baidu/Ksyun sanitizer 对称）：
 *   - HuaweicloudApiException（结构化 API 错误，HuaweicloudRestClient 由 HTTP 非 2xx / 响应体 error_code 归一）：
 *     取华为云错误码 + 华为云自带描述（均来自响应体）拼安全文案。
 *   - 其余（本地/网络/未知 Throwable）：只给错误类名 + 通用文案，绝不回传 getMessage()。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御）。
 */
class HuaweicloudErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof HuaweicloudApiException) {
            // 结构化 API 错误：code + 描述取自响应体（error_code / error_msg 或 HTTP 状态码），不含请求/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'HuaweiCloudError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '华为云接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle 失败 message 可能含请求 URL）：只暴露类名
        return '华为云调用失败: '.class_basename($e);
    }
}
