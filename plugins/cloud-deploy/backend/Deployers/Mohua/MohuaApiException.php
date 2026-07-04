<?php

namespace Plugins\CloudDeploy\Deployers\Mohua;

use RuntimeException;

/**
 * 嘿华云 REST 接口错误（区别于本地/网络层异常）。
 *
 * 嘿华云 API 走 JWT 鉴权（登录换 token 后放 JWT 请求头，非 URL/body），响应体形如 `{status, msg}`。
 * MohuaClient 把「HTTP 非 2xx」或「响应体 status != 200」归一为本异常：携带 status 作错误码 + 自带 msg
 * （取自响应体，不含凭证）。MohuaErrorSanitizer 据此类型只取 code + 安全描述。
 */
class MohuaApiException extends RuntimeException
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
