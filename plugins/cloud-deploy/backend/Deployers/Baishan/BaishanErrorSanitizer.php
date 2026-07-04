<?php

namespace Plugins\CloudDeploy\Deployers\Baishan;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 白山云 REST 异常脱敏（共用于所有白山云 deployer/uploader）。
 *
 * 泄露面：白山云鉴权把 token 放进 **URL 查询串**（?token=...），故底层 Guzzle 网络异常（连接失败等）的
 * message 可能含带 token 的请求 URL → 仅暴露类名，绝不回传 getMessage()。而响应体的 code/message 不含
 * token，安全。
 *
 * 策略（与 Aliyun/Tencent/Ksyun/Baidu sanitizer 对称）：
 *   - BaishanApiException（结构化 API 错误，BaishanRestClient 由 HTTP 非 2xx / 响应体 code 归一）：
 *     取白山云错误码 + 白山云自带描述（均来自响应体）拼安全文案。
 *   - 其余（本地/网络/未知 Throwable）：只给错误类名 + 通用文案。
 * 末尾统一过 CredentialScrubber 兜底再扫一遍 AK/SK/签名/私钥 pattern（纵深防御）。
 */
class BaishanErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof BaishanApiException) {
            // 结构化 API 错误：code + 描述取自响应体（code / message 或 HTTP 状态码），不含请求/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'BaishanError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '白山云接口返回错误';

            return "[$code] $msg";
        }

        // 网络/本地/未知错误（Guzzle 失败 message 可能含带 token 的请求 URL）：只暴露类名
        return '白山云调用失败: '.class_basename($e);
    }
}
