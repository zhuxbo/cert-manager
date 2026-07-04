<?php

namespace Plugins\CloudDeploy\Deployers\Apisix;

use RuntimeException;

/**
 * APISIX Admin API 接口错误（区别于本地/网络层异常）。
 *
 * APISIX 走「X-API-KEY」请求头鉴权（非 URL/body），响应体形如 `{error_msg}`（错误时）。
 * ApisixClient 把「HTTP 非 2xx」归一为本异常：携带 HTTP 状态码作错误码 + 自带 message
 * （取自响应体 error_msg，不含 api key）。ApisixErrorSanitizer 据此类型只取 code + 安全描述。
 */
class ApisixApiException extends RuntimeException
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
