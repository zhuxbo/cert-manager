<?php

namespace Plugins\CloudDeploy\Deployers\Cdnfly;

use RuntimeException;

/**
 * Cdnfly REST 接口错误（区别于本地/网络层异常）。
 *
 * Cdnfly（自建 CDN 系统）走「API-Key + API-Secret」请求头鉴权（非 URL/body），响应体形如
 * `{code, msg, data}`。CdnflyClient 把「HTTP 非 2xx」或「响应体 code 非空且非 0」归一为本异常：
 * 携带 code 作错误码 + 自带 msg（取自响应体，不含凭证）。CdnflyErrorSanitizer 据此类型只取 code + 安全描述。
 */
class CdnflyApiException extends RuntimeException
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
