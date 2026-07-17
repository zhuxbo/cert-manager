<?php

namespace Plugins\CloudDeploy\Deployers\Netlify;

use RuntimeException;

/**
 * Netlify REST 接口错误（区别于本地/网络层异常）。
 *
 * Netlify API 走 Bearer Token 鉴权（凭证在 Authorization 请求头，非 URL/body），失败响应体形如
 * `{code, message}`。NetlifyClient 把「HTTP 非 2xx」或「响应体带非零 code」归一为本异常：
 * 携带 Netlify 错误码 + 自带 message（取自响应体，不含凭证）。
 * NetlifyErrorSanitizer 据此类型只取 code + 安全描述。
 */
class NetlifyApiException extends RuntimeException
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
