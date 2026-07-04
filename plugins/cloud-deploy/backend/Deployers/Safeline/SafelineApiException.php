<?php

namespace Plugins\CloudDeploy\Deployers\Safeline;

use RuntimeException;

/**
 * 雷池 WAF（SafeLine）开放接口错误（区别于本地/网络层异常）。
 *
 * SafeLine 开放接口（base {serverUrl}）走 `X-SLCE-API-TOKEN` 头鉴权。响应体形如 `{err, msg, data}`：
 * `err` 非空即业务失败。SafelineClient 把「HTTP 非 2xx」或「err 非空」归一为本异常：携带 err 错误码
 * （或 HTTP 状态码）+ 自带 msg（取自响应体，不含凭证）。SafelineErrorSanitizer 据此类型只取 code + 描述。
 */
class SafelineApiException extends RuntimeException
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
