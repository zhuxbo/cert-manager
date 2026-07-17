<?php

namespace Plugins\CloudDeploy\Deployers\Ksyun;

use RuntimeException;

/**
 * 金山云 REST 接口错误（区别于本地/网络层异常）。
 *
 * 金山云无官方 PHP SDK，cdn/kcm 走自写 REST（KsyunRestClient），无现成 SDK 异常类型可 catch。
 * KsyunRestClient 把「HTTP 非 2xx」或「响应体含 Error 对象（金山云开放 API 统一错误体）」归一为本异常：
 * 携带金山云错误码（响应体 Error.Code，或 HTTP 状态码兜底）+ 金山云自带 Error.Message 描述。
 *
 * 脱敏说明：金山云签名走 query/body 内的 Signature 字段（HMAC-SHA256），但响应体里的 Error.Code /
 * Error.Message 不含 AccessKeyId/SecretAccessKey/Signature（签名是请求侧、不回显），安全。仍由
 * KsyunErrorSanitizer 走 CredentialScrubber 兜底再扫一遍。
 */
class KsyunApiException extends RuntimeException
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
