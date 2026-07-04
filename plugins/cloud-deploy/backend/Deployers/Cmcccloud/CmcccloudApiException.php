<?php

namespace Plugins\CloudDeploy\Deployers\Cmcccloud;

use RuntimeException;

/**
 * 移动云 eCloud REST 接口错误（区别于本地/网络层异常）。
 *
 * 移动云无官方 PHP SDK（其 Go SDK ecloudsdkcore 走自有 AKSK 签名）。本插件照其 REST 协议自写
 * CmcccloudRestClient，无现成 SDK 异常类型可 catch。CmcccloudRestClient 把「HTTP 非 2xx」或
 * 「响应体 state 为 ERROR/EXCEPTION/FORBIDDEN（eCloud 统一响应体 {state,errorCode,errorMessage,body}）」
 * 归一为本异常：携带 eCloud 错误码（响应体 errorCode，或 HTTP 状态码兜底）+ eCloud 自带 errorMessage 描述。
 *
 * 脱敏说明：eCloud AKSK 签名以 Signature 字段塞进请求 URL 查询串，**不回显**在响应里，故响应体的
 * errorCode/errorMessage 不含 AccessKey/SecretKey/Signature，安全。仍由 CmcccloudErrorSanitizer 走
 * CredentialScrubber 兜底。
 */
class CmcccloudApiException extends RuntimeException
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
