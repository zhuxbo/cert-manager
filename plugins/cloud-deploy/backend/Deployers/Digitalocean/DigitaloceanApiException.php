<?php

namespace Plugins\CloudDeploy\Deployers\Digitalocean;

use RuntimeException;

/**
 * DigitalOcean REST 接口错误（区别于本地/网络层异常）。
 *
 * DO API v2 走 Bearer Token 鉴权（凭证在 Authorization 请求头，非 URL/body）。DigitaloceanClient 把
 * 「HTTP 非 2xx」归一为本异常：携带 DO 错误标识（id）+ message（取自响应体，不含 token）。
 */
class DigitaloceanApiException extends RuntimeException
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
