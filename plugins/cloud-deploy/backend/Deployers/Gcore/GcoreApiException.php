<?php

namespace Plugins\CloudDeploy\Deployers\Gcore;

use RuntimeException;

/**
 * Gcore REST 接口错误（区别于本地/网络层异常）。
 *
 * Gcore API 走 APIKey 请求头鉴权（凭证在 Authorization: APIKey {token} 请求头，非 URL/body），失败响应体
 * 形如 `{message, errors}`（gcore.ErrorResponse）。GcoreClient 把「HTTP 非 2xx」归一为本异常：
 * 携带 HTTP 状态码 + 自带 message/errors（取自响应体，不含凭证）。
 * GcoreErrorSanitizer 据此类型只取 code + 安全描述。
 */
class GcoreApiException extends RuntimeException
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
