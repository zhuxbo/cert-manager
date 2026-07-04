<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use RuntimeException;

/**
 * 华为云 REST 接口错误（区别于本地/网络层异常）。
 *
 * 华为云无随包安装的 PHP SDK（依赖体量大、按官方文档手写 REST + SDK-HMAC-SHA256 签名），无现成 SDK 异常类型可 catch。
 * HuaweicloudRestClient 把「HTTP 非 2xx」或「响应体含 error_code/error_msg（华为云统一错误体）」归一为本异常：
 * 携带华为云错误码（响应体 error_code，或 HTTP 状态码兜底）+ 华为云自带 error_msg 描述。
 *
 * 脱敏说明：华为云签名走 Authorization 请求头（SDK-HMAC-SHA256，AK 明文进头、SK 仅参与 HMAC 不回显），响应体里的
 * error_code / error_msg 不含 AccessKeyId/SecretAccessKey/Signature，安全。仍由 HuaweicloudErrorSanitizer
 * 走 CredentialScrubber 兜底再扫一遍。
 */
class HuaweicloudApiException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly string $errorMessage,
    ) {
        parent::__construct("[$errorCode] $errorMessage");
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
