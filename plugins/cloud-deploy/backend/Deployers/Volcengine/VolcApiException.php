<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use RuntimeException;

/**
 * 火山引擎 REST 接口错误（区别于本地/网络层异常）。
 *
 * 火山引擎走自写 REST（无官方 PHP SDK），故无现成 SDK 异常类型可 catch。
 * VolcRestClient 把「HTTP 非 2xx」或「响应体含 ResponseMetadata.Error」归一为本异常：
 * 携带火山错误码（响应体 Error.Code 或 HTTP 状态码）+ 火山自带 Error.Message 描述
 * （取自响应体，**不含**请求 URI/凭证 —— 火山签名放 Authorization 请求头，URL/body 无 AK/SK）。
 * VolcErrorSanitizer 据此类型只取 code + 安全描述。
 *
 * 注意：Message 虽来自火山响应体（安全），仍由 sanitizer 走 CredentialScrubber 兜底再扫一遍。
 */
class VolcApiException extends RuntimeException
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
