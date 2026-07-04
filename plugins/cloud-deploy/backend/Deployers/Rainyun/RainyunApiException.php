<?php

namespace Plugins\CloudDeploy\Deployers\Rainyun;

use RuntimeException;

/**
 * 雨云 REST 接口错误（区别于本地/网络层异常）。
 *
 * 雨云 API v2 走 API Key 鉴权（凭证在 X-API-Key 请求头，非 URL/body），响应体形如
 * `{code, message, data}`。RainyunClient 把「HTTP 非 2xx」或「响应体 code/100 != 2」归一为本异常：
 * 携带 code 作错误码 + 自带 message（取自响应体，不含凭证）。RainyunErrorSanitizer 据此类型只取 code + 安全描述。
 */
class RainyunApiException extends RuntimeException
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
