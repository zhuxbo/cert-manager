<?php

namespace Plugins\CloudDeploy\Deployers\Onepanel;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 1Panel REST 异常脱敏（共用于所有 1Panel deployer）。
 *
 * 泄露面：1Panel 走 api-key + 时间戳 MD5 签名（apiKey 仅用于本地算签名、放 1Panel-Token 请求头，
 * 不出现在 URL 查询串/响应体），故响应体里的 code/message 不含 apiKey，安全。但底层 Guzzle 网络异常
 * message 可能含请求 URL → 仅暴露类名。
 *
 * 策略（与 Cloudflare/Upyun sanitizer 对称）：
 *   - OnepanelApiException（结构化 API 错误）：取 1Panel 业务码 + 自带 message 拼安全文案。
 *   - GuzzleException（网络/传输层）：只暴露类名 —— 绝不回传 getMessage()。
 *   - 其余未知 Throwable：同样只给类名 + 通用文案。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class OnepanelErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof OnepanelApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'OnePanelError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '1Panel 接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return '1Panel 调用失败: '.class_basename($e);
        }

        return '1Panel 调用失败: '.class_basename($e);
    }
}
