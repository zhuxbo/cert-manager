<?php

namespace Plugins\CloudDeploy\Deployers\Cpanel;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * cPanel UAPI 异常脱敏。
 *
 * 泄露面：cPanel 令牌放 `Authorization` 头（非 URL、非 body），故响应体错误码/描述不含凭证，安全。
 * 但 install_ssl 是 GET 调用、**cert/key/cabundle PEM 走 URL 查询串**——底层 Guzzle 网络异常 message
 * 可能带出请求 URL（连同 PEM）。故：
 *   - CpanelApiException（结构化 API 错误，HTTP 非 2xx / status==0 归一）：取状态码 + 自带描述
 *     （取自响应体 errors/warnings/messages，不含凭证）。
 *   - GuzzleException / 其余未知 Throwable：只暴露类名，绝不回传 getMessage()（可能含 URL+PEM）。
 * 末尾过 CredentialScrubber 兜底再扫一遍（纵深防御）。
 */
class CpanelErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof CpanelApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'CPanelError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'cPanel 接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            // 网络/传输层异常 message 可能含请求 URL（GET install_ssl 的 PEM 在查询串）：只暴露类名
            return 'cPanel 调用失败: '.class_basename($e);
        }

        return 'cPanel 调用失败: '.class_basename($e);
    }
}
