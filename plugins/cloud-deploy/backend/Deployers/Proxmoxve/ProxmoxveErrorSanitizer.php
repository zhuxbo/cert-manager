<?php

namespace Plugins\CloudDeploy\Deployers\Proxmoxve;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Proxmox VE API 异常脱敏（共用于所有 Proxmox VE deployer）。
 *
 * 泄露面：Proxmox VE 走「Authorization: PVEAPIToken={tokenId}={tokenSecret}」请求头鉴权（凭证在
 * 请求头，非 URL 查询串/body），故响应体里的错误描述不含 token，安全。但底层 Guzzle 网络异常
 * message 可能含请求 URL（自建服务地址）→ 仅暴露类名。
 *
 * 策略（与 Cloudflare/Kong sanitizer 对称）：
 *   - ProxmoxveApiException（结构化 API 错误，由 HTTP 非 2xx 归一）：取 HTTP 状态码 + 安全描述。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class ProxmoxveErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof ProxmoxveApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'ProxmoxveError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Proxmox VE 接口返回错误';

            return "[$code] $msg";
        }

        return 'Proxmox VE 调用失败: '.class_basename($e);
    }
}
