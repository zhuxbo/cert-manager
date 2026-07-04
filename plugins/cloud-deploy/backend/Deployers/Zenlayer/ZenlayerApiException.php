<?php

namespace Plugins\CloudDeploy\Deployers\Zenlayer;

use RuntimeException;

/**
 * Zenlayer REST 接口错误（区别于本地/网络层异常）。
 *
 * Zenlayer 无官方 PHP SDK，cdn/zga 走自写 REST（ZenlayerRestClient），无现成 SDK 异常类型可 catch。
 * ZenlayerRestClient 把「HTTP 非 2xx」或「响应体 code 非空（Zenlayer 统一错误体 {requestId,code,message}）」
 * 归一为本异常：携带 Zenlayer 错误码（响应体 code，或 HTTP 状态码兜底）+ Zenlayer 自带 message 描述。
 *
 * 脱敏说明：Zenlayer ZC2-HMAC-SHA256 签名以 Authorization 请求头下发，**不回显**在响应里，故响应体的
 * code/message 不含 SecretKeyId/SecretKeyPassword/签名，安全。仍由 ZenlayerErrorSanitizer 走
 * CredentialScrubber 兜底。
 */
class ZenlayerApiException extends RuntimeException
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
