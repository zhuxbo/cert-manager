<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 京东云 REST 异常脱敏（共用于所有京东云 deployer/uploader）。
 *
 * 泄露面：京东云 JDCLOUD2-HMAC-SHA256 鉴权把凭证派生的 Authorization 放 **请求头**（非 URL 查询串、
 * 非 body），故响应体里的错误码/描述不含 AccessKeyId/AccessKeySecret，签名值也不进异常。但底层 Guzzle
 * 网络异常（连接失败/超时）的 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Aliyun/Tencent/Qiniu/Baidu sanitizer 对称，catch Throwable 多分支）：
 *   - JdcloudApiException（结构化 API 错误，JdcloudRestClient 由 HTTP 非 2xx / 响应体 error.code 归一）：
 *     取京东云错误码 + 京东云自带描述（均来自响应体）拼安全文案。
 *   - 其余（本地/网络/JSON 解码/未知 Throwable，message 可能含请求 URL）：只给错误类名 + 通用文案，
 *     绝不回传 getMessage()。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御）。
 */
class JdcloudErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof JdcloudApiException) {
            // 结构化 API 错误：code + 描述取自响应体（error.code/error.message 或 HTTP 状态码），不含请求/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'JdcloudError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '京东云接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/解码/未知错误（message 可能含请求 URL）：只暴露类名
        return '京东云调用失败: '.class_basename($e);
    }
}
