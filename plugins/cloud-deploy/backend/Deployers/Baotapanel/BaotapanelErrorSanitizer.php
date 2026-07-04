<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanel;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 宝塔面板 REST 异常脱敏（共用于所有宝塔面板 deployer）。
 *
 * 泄露面：宝塔走 api_sk + request_time MD5 签名（apiKey 仅用于本地算签名、经 request_token 表单字段提交，
 * 不出现在 URL 查询串/响应体），故响应体里的 msg 不含 apiKey，安全。但底层 Guzzle 网络异常 message 可能
 * 含请求 URL → 仅暴露类名。
 *
 * 策略（与 Cloudflare/Upyun sanitizer 对称）：
 *   - BaotapanelApiException（结构化 API 错误）：取 code + 自带 msg 拼安全文案。
 *   - GuzzleException（网络/传输层）：只暴露类名 —— 绝不回传 getMessage()。
 *   - 其余未知 Throwable：同样只给类名 + 通用文案。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class BaotapanelErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof BaotapanelApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'BaotaError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '宝塔面板接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            return '宝塔面板调用失败: '.class_basename($e);
        }

        return '宝塔面板调用失败: '.class_basename($e);
    }
}
