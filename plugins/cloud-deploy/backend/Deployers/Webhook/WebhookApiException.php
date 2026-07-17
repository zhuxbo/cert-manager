<?php

namespace Plugins\CloudDeploy\Deployers\Webhook;

use RuntimeException;

/**
 * Webhook 回调 HTTP 错误（区别于本地/网络层异常）。
 *
 * Webhook 是用户自定义回调地址，鉴权方式由用户自行在 headers 配置（如 Authorization）。WebhookClient
 * 把「HTTP 非 2xx」归一为本异常：仅携带 HTTP 状态码作错误码 + 通用描述（**不回传响应体**，因响应体由
 * 用户的服务器返回、内容不可控，可能含敏感信息或被回声攻击）。WebhookErrorSanitizer 据此类型只取 code。
 */
class WebhookApiException extends RuntimeException
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
