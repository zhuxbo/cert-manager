<?php

namespace Plugins\CloudDeploy\Deployers\Cpanel;

use RuntimeException;

/**
 * cPanel UAPI 接口错误（区别于本地/网络层异常）。
 *
 * cPanel UAPI（base {serverUrl}/execute）走 `Authorization: cpanel <user>:<token>` 头鉴权。
 * 响应体形如 `{status, messages, warnings, errors, data}`。CpanelClient 把「HTTP 非 2xx」或
 * 「status==0」归一为本异常：携带状态码 + 自带描述（取自响应体 errors/warnings/messages，不含凭证）。
 * CpanelErrorSanitizer 据此类型只取 code + 安全描述。
 */
class CpanelApiException extends RuntimeException
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
