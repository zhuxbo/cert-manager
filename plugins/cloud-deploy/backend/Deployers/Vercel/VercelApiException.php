<?php

namespace Plugins\CloudDeploy\Deployers\Vercel;

use RuntimeException;

/**
 * Vercel REST 接口错误（区别于本地/网络层异常）。
 *
 * Vercel API 走 Bearer Token 鉴权（凭证在 Authorization 请求头，非 URL/body），响应体在失败时形如
 * `{error:{code,message}}`。VercelClient 把「HTTP 非 2xx」或「响应体带 error.code」归一为本异常：
 * 携带 Vercel 错误码 + 自带 message（取自响应体 error，不含凭证）。
 * VercelErrorSanitizer 据此类型只取 code + 安全描述。
 */
class VercelApiException extends RuntimeException
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
