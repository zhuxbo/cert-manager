<?php

namespace Plugins\CloudDeploy\Deployers\K8s;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Kubernetes REST 异常脱敏（共用于所有 k8s deployer）。
 *
 * 泄露面：Kubernetes API 走 Bearer Token 鉴权（token 在 Authorization 请求头，非 URL 查询串、非 body），
 * 故响应体里的 reason/message 不含 token，安全。但底层 Guzzle 网络异常 message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Cloudflare/Upyun sanitizer 对称）：
 *   - K8sApiException（结构化 API 错误，由 HTTP 非 2xx 归一）：取 K8s reason/HTTP 码 + 自带 message
 *     （来自响应体 Status 对象）拼安全文案。
 *   - GuzzleException（网络/传输层）：只暴露类名 —— 绝不回传 getMessage()（可能含请求 URI）。
 *   - 其余未知 Throwable：同样只给类名 + 通用文案。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class K8sErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof K8sApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'KubernetesError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Kubernetes 接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return 'Kubernetes 调用失败: '.class_basename($e);
        }

        return 'Kubernetes 调用失败: '.class_basename($e);
    }
}
