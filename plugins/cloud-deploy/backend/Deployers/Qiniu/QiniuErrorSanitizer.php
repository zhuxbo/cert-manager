<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 七牛云 REST 异常脱敏（共用于所有七牛云 deployer/uploader）。
 *
 * 泄露面：七牛 TC（Qiniu/QBox）鉴权把凭证放 Authorization 请求头（非 URL 查询串、非 body），
 * 故响应体里的错误码/描述不含 AccessKey/SecretKey，安全。但底层 curl 失败（Qiniu\Http\Client
 * 在 curl_errno≠0 时把 curl_error 拼进 Response->error）的 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Aliyun/Tencent sanitizer 对称）：
 *   - QiniuApiException（结构化 API 错误，QiniuRestClient 由 HTTP 非 2xx / 响应体 code≠0 归一）：
 *     取七牛错误码 + 七牛自带描述（均来自响应体）拼安全文案。
 *   - 其余（本地/网络/未知 Throwable）：只给错误类名 + 通用文案，绝不回传 getMessage()。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御）。
 */
class QiniuErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof QiniuApiException) {
            // 结构化 API 错误：code + 描述取自响应体（HTTP 状态码或 body.code + body.error），不含请求/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'QiniuError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '七牛云接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（curl 失败 message 可能含请求 URL）：只暴露类名
        return '七牛云调用失败: '.class_basename($e);
    }
}
