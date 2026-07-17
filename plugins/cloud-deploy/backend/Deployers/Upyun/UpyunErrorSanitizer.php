<?php

namespace Plugins\CloudDeploy\Deployers\Upyun;

use GuzzleHttp\Exception\GuzzleException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 又拍云控制台 REST 异常脱敏（共用于所有又拍云 deployer/uploader）。
 *
 * 泄露面（关键差异于 Qiniu/阿里/腾讯）：又拍云控制台**无 AK/SK**，鉴权靠账号登录——
 * username/password 仅出现在登录请求 `POST /accounts/signin/` 的 **body**（非 URL、非业务接口）。
 * 故威胁是：底层 Guzzle 网络异常（RequestException 等）的 message/trace 可能带出请求 URI，甚至
 * （在某些 Guzzle 版本/配置下）请求 body → 连带 username/password。
 *
 * 策略（与 Qiniu/Cloudflare sanitizer 对称）：
 *   - UpyunApiException（结构化 API 错误，UpyunRestClient 由 HTTP 非 2xx / data.error_code≠0 归一）：
 *     取又拍云错误码 + 自带 message（均来自响应体，不含凭证）拼安全文案。
 *   - GuzzleException（网络/传输层）：只暴露类名 —— 绝不回传 getMessage()（可能含请求 URI/body）。
 *   - 其余未知 Throwable：同样只给类名 + 通用文案。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍（纵深防御；又拍云凭证非 AK/SK pattern，故「只暴露类名」
 * 才是主防线，CredentialScrubber 仅作万一拼进 PEM/AK 子串时的兜底）。
 */
class UpyunErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof UpyunApiException) {
            // 结构化 API 错误：code + 描述取自响应体（业务 error_code 或 HTTP 状态码 + message），不含凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'UpyunError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '又拍云接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof GuzzleException) {
            // 网络/传输层异常 message 可能含请求 URI 或登录 body（username/password）：只暴露类名
            return '又拍云调用失败: '.class_basename($e);
        }

        // 其余未知错误：同样只暴露类名，绝不回传 getMessage()
        return '又拍云调用失败: '.class_basename($e);
    }
}
