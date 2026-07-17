<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Exception\AwsException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * AWS SDK 异常脱敏（共用于所有 AWS deployer/uploader）。
 *
 * 泄露面：AWS SigV4 签名把凭证放 Authorization 请求头（非 URL 查询串、非 body），
 * 故服务端结构化错误（4xx/5xx）→ AwsException，其 getAwsErrorCode()/getAwsErrorMessage()
 * 取自响应体的 <Error><Code>/<Message>（或 JSON __type/message），不含 AK/SK，安全。
 *
 * **但绝不回传 AwsException::getMessage()**：其模板把 HTTP 请求摘要（method + 完整 URI + 部分 header）
 * 拼进 message，URI 可能带 X-Amz-Credential / X-Amz-Signature（presigned 风格）或 host 信息 → 严禁整段回传。
 * 同理网络/本地错误（连接失败、超时、credentials provider 异常）不是 AwsException（或 errorCode 为空），
 * message 由底层 Guzzle 异常拼成、含签名 URI → 落入「只暴露类名」分支。
 *
 * 故策略：仅当是「结构化 API 错误」（AwsException 且 errorCode 非空）时，用 errorCode + 服务端错误描述
 * 拼安全文案；其余一律只给错误类名 + 通用文案。最后再过 CredentialScrubber 兜底扫一遍 AK/SK/签名/私钥 pattern。
 */
class AwsErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        // 兜底凭证扫描（纵深防御）：精确脱敏后再扫一遍 AK/SK/签名/私钥 pattern，威胁模型边界被破时拦截。
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof AwsException) {
            // 结构化 API 错误：errorCode/errorMessage 取自服务端响应体（XML <Error> 或 JSON），不含请求 URI/凭证。
            $code = $e->getAwsErrorCode();
            $code = is_string($code) && $code !== '' ? $code : null;

            // errorCode 为空 ⇒ 多为网络/本地包装异常（非服务端结构化错误），不回传 message，只暴露类名。
            if ($code === null) {
                return 'AWS 调用失败: '.class_basename($e);
            }

            $msg = $e->getAwsErrorMessage();
            $msg = is_string($msg) && $msg !== '' ? $msg : 'AWS 接口返回错误';

            return "[$code] $msg";
        }

        // 网络/未知错误（message/previous 可能含签名 URI）：只暴露类名
        return 'AWS 调用失败: '.class_basename($e);
    }
}
