<?php

namespace Plugins\CloudDeploy\Deployers\Dogecloud;

use RuntimeException;

/**
 * 多吉云 REST 接口错误（区别于本地/网络层异常）。
 *
 * 多吉云无官方 PHP SDK，CDN 走自写 REST（DogecloudRestClient），无现成 SDK 异常类型可 catch。
 * DogecloudRestClient 把「HTTP 非 2xx」或「响应体 code 非 0 且非 200（多吉云统一响应体 {code,msg,data}）」
 * 归一为本异常：携带多吉云错误码（响应体 code，或 HTTP 状态码兜底）+ 多吉云自带 msg 描述。
 *
 * 脱敏说明：多吉云签名走 Authorization 请求头（HMAC-SHA1，TOKEN {accessKey}:{sig}），**不回显**在响应里，
 * 故响应体里的 code/msg 不含 AccessKey/SecretKey/签名，安全。仍由 DogecloudErrorSanitizer 走
 * CredentialScrubber 兜底再扫一遍。
 */
class DogecloudApiException extends RuntimeException
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
