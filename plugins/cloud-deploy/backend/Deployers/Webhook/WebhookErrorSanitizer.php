<?php

namespace Plugins\CloudDeploy\Deployers\Webhook;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Webhook 回调异常脱敏。
 *
 * 泄露面：Webhook 鉴权信息（如 Authorization 头）由用户配置进 headers，请求时随 Guzzle 发出。底层
 * Guzzle 网络异常 message 可能含请求 URL（webhook url 一般非敏感，但可能带查询串凭证）→ 仅暴露类名。
 * 请求体含证书私钥（${CERTIMATE_DEPLOYER_PRIVATEKEY} 等替换值），故 WebhookClient 归一错误时**不带响应体/请求体**。
 *
 * 策略（与 Cloudflare/Upyun sanitizer 对称）：
 *   - WebhookApiException（HTTP 非 2xx 归一）：取 HTTP 状态码 + 通用安全描述。
 *   - GuzzleException（网络/传输层）：只暴露类名 —— 绝不回传 getMessage()。
 *   - 其余未知 Throwable：同样只给类名 + 通用文案。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御，兜底 headers 里的 token / 私钥 PEM 子串）。
 */
class WebhookErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof WebhookApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'WebhookError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Webhook 回调返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return 'Webhook 调用失败: '.class_basename($e);
        }

        return 'Webhook 调用失败: '.class_basename($e);
    }
}
