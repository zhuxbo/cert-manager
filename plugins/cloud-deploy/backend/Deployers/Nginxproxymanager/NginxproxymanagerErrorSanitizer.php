<?php

namespace Plugins\CloudDeploy\Deployers\Nginxproxymanager;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Nginx Proxy Manager API 异常脱敏（共用于所有 NPM deployer）。
 *
 * 泄露面：NPM 走「Authorization: Bearer {token}」请求头鉴权（凭证在请求头，非 URL/body 响应），故响应体
 * 里的错误描述不含 token，安全。但登录请求体含 identity/secret、底层 Guzzle 网络异常 message 可能含
 * 请求 URL（自建服务地址）与登录 body → 网络类异常仅暴露类名。
 *
 * 策略（与 Cloudflare/Upyun sanitizer 对称）：
 *   - NginxproxymanagerApiException（结构化 API 错误，由 HTTP 非 2xx / error 非空归一）：取错误码 +
 *     自带描述（来自响应体 error）拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class NginxproxymanagerErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof NginxproxymanagerApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'NginxProxyManagerError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Nginx Proxy Manager 接口返回错误';

            return "[$code] $msg";
        }

        return 'Nginx Proxy Manager 调用失败: '.class_basename($e);
    }
}
