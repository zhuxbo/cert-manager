<?php

namespace Plugins\CloudDeploy\Deployers\Digitalocean;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * DigitalOcean REST 异常脱敏（共用于所有 DO deployer/uploader）。
 *
 * DO Bearer Token 放 Authorization 请求头（非 URL/body），响应体错误不含 token。但底层 Guzzle 网络
 * 异常 message 可能含请求 URL → 仅暴露类名。策略与 Cloudflare/Qiniu sanitizer 对称。
 */
class DigitaloceanErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof DigitaloceanApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'DigitalOceanError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'DigitalOcean 接口返回错误';

            return "[$code] $msg";
        }

        return 'DigitalOcean 调用失败: '.class_basename($e);
    }
}
