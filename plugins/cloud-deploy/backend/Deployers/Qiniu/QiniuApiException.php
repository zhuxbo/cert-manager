<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use RuntimeException;

/**
 * 七牛云 REST 接口错误（区别于本地/网络层异常）。
 *
 * 七牛 SSL/CDN/Kodo/Pili 走自写 REST（php-sdk 无对应高层 client），故无现成 SDK 异常类型可 catch。
 * QiniuRestClient 把「HTTP 非 2xx」或「响应体 code 不在成功码 0/200」归一为本异常：携带七牛错误码
 * （HTTP 状态码或响应体 code）+ 七牛自带 error 描述（取自响应体 error 字段，**不含**请求 URI/凭证 —— 七牛 TC 鉴权
 * 把签名放 Authorization 请求头，URL/body 无凭证）。QiniuErrorSanitizer 据此类型只取 code + 安全描述。
 *
 * 注意：error 描述虽来自七牛响应体（安全），仍由 sanitizer 走 CredentialScrubber 兜底再扫一遍。
 */
class QiniuApiException extends RuntimeException
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
