<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use RuntimeException;

/**
 * 京东云 REST 接口错误（区别于本地/网络层异常）。
 *
 * 京东云走自写 REST（无官方 PHP SDK），无现成 SDK 异常类型可 catch。JdcloudRestClient 把
 * 「HTTP 非 2xx」或「响应体 error.code 非空」归一为本异常：携带京东云错误码（响应体 error.code 或
 * HTTP 状态码）+ 京东云自带 error.message 描述（取自响应体 error 字段，**不含**请求 URI/凭证 ——
 * 京东云 JDCLOUD2-HMAC-SHA256 签名放 Authorization 请求头，URL/body 无凭证）。
 * JdcloudErrorSanitizer 据此类型只取 code + 安全描述。
 *
 * 注意：error 描述虽来自京东云响应体（安全），仍由 sanitizer 走 CredentialScrubber 兜底再扫一遍。
 */
class JdcloudApiException extends RuntimeException
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
