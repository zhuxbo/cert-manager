<?php

namespace Plugins\CloudDeploy\Deployers\Googlecloud;

use RuntimeException;

/**
 * Google Cloud REST 接口错误（区别于本地/网络层异常）。
 *
 * GCP 走 OAuth2 Bearer（access_token 在 Authorization 请求头，非 URL/body）。GooglecloudClient 把
 * 「HTTP 非 2xx」归一为本异常：携带 GCP 错误状态（status）+ message（取自响应体 error.message，不含凭证）。
 * GooglecloudErrorSanitizer 据此类型只取 status + 安全描述。
 */
class GooglecloudApiException extends RuntimeException
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
