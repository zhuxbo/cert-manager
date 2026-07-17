<?php

namespace Plugins\CloudDeploy\Deployers\Nginxproxymanager;

use RuntimeException;

/**
 * Nginx Proxy Manager API 接口错误（区别于本地/网络层异常）。
 *
 * NPM 走「Authorization: Bearer {token}」请求头鉴权（token 来自账号登录 POST /tokens 或直接配置的
 * JWT），响应体形如 `{error: {code, message} | "string"}`。NginxproxymanagerClient 把「HTTP 非 2xx」
 * 或「响应体 error 非空」归一为本异常：携带错误码 + 响应体 error 描述（不含 identity/secret/token）。
 * NginxproxymanagerErrorSanitizer 据此类型只取 code + 安全描述。
 */
class NginxproxymanagerApiException extends RuntimeException
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
