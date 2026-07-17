<?php

namespace Plugins\CloudDeploy\Deployers\Azure;

use RuntimeException;

/**
 * Azure REST 接口错误（区别于本地/网络层异常）。
 *
 * Azure 走 OAuth2 Bearer（access_token 在 Authorization 请求头，非 URL/body）。AzureKeyVaultClient 把
 * 「HTTP 非 2xx」归一为本异常：携带 Azure 错误码（error.code）+ message（取自响应体 error.message，
 * 不含 clientSecret/token）。AzureErrorSanitizer 据此类型只取 code + 安全描述。
 */
class AzureApiException extends RuntimeException
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
