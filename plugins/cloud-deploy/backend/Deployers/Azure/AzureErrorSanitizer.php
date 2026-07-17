<?php

namespace Plugins\CloudDeploy\Deployers\Azure;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * Azure REST 异常脱敏（共用于所有 Azure deployer/uploader）。
 *
 * 泄露面：OAuth2 access_token 放 Authorization 请求头（非 URL 查询串、非 body），故响应体里的
 * 错误码/描述不含 token，安全。clientSecret 仅在换 token 时进 body，**绝不出现在响应错误体**。
 * 但底层 Guzzle 网络异常 message 可能含请求 URL → 仅暴露类名。末尾过 CredentialScrubber 兜底。
 *
 * 策略（与其他 sanitizer 对称）：
 *   - AzureApiException（结构化 API 错误）：取 Azure 错误码 + 自带描述拼安全文案。
 *   - 其余（网络/本地/未知 Throwable）：只给类名 + 通用文案，绝不回传 getMessage()。
 */
class AzureErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof AzureApiException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'AzureError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : 'Azure 接口返回错误';

            return "[$code] $msg";
        }

        return 'Azure 调用失败: '.class_basename($e);
    }
}
