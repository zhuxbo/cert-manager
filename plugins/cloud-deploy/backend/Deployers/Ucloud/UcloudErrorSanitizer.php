<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 优刻得（UCloud）REST 异常脱敏（共用于所有 UCloud deployer/uploader）。
 *
 * 泄露面分析（自写 UcloudRestClient + GuzzleHttp）：
 * - UCloud 签名走请求体里的 `Signature` 参数（POST form-urlencoded body），PublicKey/PrivateKey
 *   也不在 URL。endpoint 固定 `https://api.ucloud.cn` 无 query。故服务端响应体的 RetCode/Message
 *   不含凭证，安全。
 * - **UcloudApiException**（结构化 API 错误，UcloudRestClient 由 HTTP 非 2xx / RetCode≠0 归一）：
 *   取 UCloud 错误码 + Message（均来自响应体）拼安全文案。
 * - **GuzzleException / 其他 Throwable**（本地/网络错误）：Guzzle 的 RequestException message 含请求
 *   URL（本固定无凭证，但 form body 不入 message；出于最小暴露原则）只暴露类名 + 通用文案，绝不回传
 *   getMessage()。
 *
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御，与 Aliyun/Tencent/
 * Qiniu/Baidu sanitizer 对称）。
 */
class UcloudErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof UcloudApiException) {
            // 结构化 API 错误：code + Message 取自响应体（RetCode/HTTP 状态码 + Message），不含请求/凭证。
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'UcloudError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '优刻得接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle message 可能含请求 URL）：只暴露类名。
        return '优刻得调用失败: '.class_basename($e);
    }
}
