<?php

namespace Plugins\CloudDeploy\Deployers\Samwaf;

use RuntimeException;

/**
 * SamWaf 接口错误（区别于本地/网络层异常）。
 *
 * SamWaf API（base {serverUrl}/api/v1）走 `X-API-Key` 头鉴权。响应体形如 `{code, msg, data}`：
 * `code==0` 成功、非 0 失败。SamwafClient 把「HTTP 非 2xx」或「code≠0」归一为本异常：携带 code 错误码
 * （或 HTTP 状态码）+ 自带 msg（取自响应体，不含凭证）。SamwafErrorSanitizer 据此类型只取 code + 描述。
 */
class SamwafApiException extends RuntimeException
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
