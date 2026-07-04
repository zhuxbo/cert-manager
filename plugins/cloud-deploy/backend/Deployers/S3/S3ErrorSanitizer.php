<?php

namespace Plugins\CloudDeploy\Deployers\S3;

use Aws\Exception\AwsException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * S3（aws-sdk）异常脱敏。
 *
 * 与 AwsErrorSanitizer 同口径：S3Client 也是 aws-sdk，结构化错误 → AwsException，
 * getAwsErrorCode()/getAwsErrorMessage() 取自服务端响应体（XML <Error>），不含 AK/SK。
 * **绝不回传 getMessage()**：其模板拼了完整 URI（自定义 endpoint + 可能的 presigned 签名段）。
 * 非 AwsException（网络/本地）只暴露类名。末尾过 CredentialScrubber 兜底。
 */
class S3ErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof AwsException) {
            $code = $e->getAwsErrorCode();
            $code = is_string($code) && $code !== '' ? $code : null;
            if ($code === null) {
                return 'S3 调用失败: '.class_basename($e);
            }

            $msg = $e->getAwsErrorMessage();
            $msg = is_string($msg) && $msg !== '' ? $msg : 'S3 接口返回错误';

            return "[$code] $msg";
        }

        return 'S3 调用失败: '.class_basename($e);
    }
}
