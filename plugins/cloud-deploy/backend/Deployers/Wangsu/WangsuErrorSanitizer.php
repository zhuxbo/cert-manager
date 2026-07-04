<?php

namespace Plugins\CloudDeploy\Deployers\Wangsu;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 网宿云 REST 异常脱敏（共用于所有网宿云 deployer/uploader）。
 *
 * 泄露面分析（自写 WangsuRestClient + GuzzleHttp）：
 * - 网宿 CNC-HMAC-SHA256 签名走 **Authorization 请求头**（非 URL 查询串、非 body），AccessKeyId /
 *   AccessKeySecret / apiKey 均不在 URL。endpoint 固定 `https://open.chinanetcenter.com`，各 path
 *   无 query。故服务端响应体的 code/message 不含凭证，安全。
 * - **WangsuApiException**（结构化 API 错误，WangsuRestClient 由 HTTP 非 2xx / 响应体 code≠0 归一）：
 *   取网宿错误码 + message（均来自响应体）拼安全文案。
 * - **GuzzleException / 其他 Throwable**（本地/网络错误）：Guzzle RequestException 的 message 可能含
 *   请求 URL（本固定无凭证；且 cdnpro 的 apiKey 仅用于本地 AES 派生、绝不入请求）。出于最小暴露原则，
 *   只暴露类名 + 通用文案，绝不回传 getMessage()。
 *
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御，与 Aliyun/Tencent/
 * Qiniu/Baidu/Ucloud sanitizer 对称）。注：apiKey 是网宿自定义凭证、无固定字面量前缀，CredentialScrubber
 * 无专属 pattern——本 sanitizer 对网络类异常只暴露类名的设计正是不让 apiKey 有机会进入文案的根本保证。
 */
class WangsuErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof WangsuApiException) {
            // 结构化 API 错误：code + message 取自响应体（body.code/message 或 HTTP 状态码），不含请求/凭证。
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'WangsuError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '网宿云接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle message 可能含请求 URL）：只暴露类名。
        return '网宿云调用失败: '.class_basename($e);
    }
}
