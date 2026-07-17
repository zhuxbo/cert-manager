<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * BytePlus REST 异常脱敏（共用于所有 BytePlus deployer/uploader）。
 *
 * 泄露面分析：
 * - **volc/BytePlus OpenAPI 签名**（HMAC-SHA256 v4）与 **TOS 签名**（TOS4-HMAC-SHA256）都把签名写进
 *   **Authorization 请求头**（OpenAPI）/ Authorization+X-Tos-* 头（TOS），**不**进 URL 查询串、**不**进 body。
 *   故服务 host / URL / 请求体本身都不含 AK/SK，签名值也不会出现在异常里。
 * - **BytePlusApiException**（结构化网关/TOS 错误，BytePlusRestClient 由 `ResponseMetadata.Error` 或 TOS
 *   错误体 / HTTP 非 2xx 归一）：errorCode + errorMessage 取自服务端响应体，不含请求与凭证，安全。
 * - **其余 Throwable**（GuzzleHttp\Exception\*、本地/网络错误）：Guzzle 异常 message 含请求 URL（URL 虽无
 *   凭证，但出于最小暴露原则）—— 只暴露类名 + 通用文案，绝不回传 getMessage()。
 *
 * 末尾再过 CredentialScrubber（纵深防御）：万一未来某分支把凭证带进了放行文案，扫 AK/SK/签名/私钥 pattern 拦下。
 */
class BytePlusErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof BytePlusApiException) {
            // 结构化 API 错误：code + 描述取自响应体（ResponseMetadata.Error 或 TOS 错误体），不含请求/凭证。
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'BytePlusError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'BytePlus 接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle message 可能含请求 URL）：只暴露类名。
        return 'BytePlus 调用失败: '.class_basename($e);
    }
}
