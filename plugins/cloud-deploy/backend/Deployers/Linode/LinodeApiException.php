<?php

namespace Plugins\CloudDeploy\Deployers\Linode;

use RuntimeException;

/**
 * Linode REST 接口错误（区别于本地/网络层异常）。
 *
 * Linode API 走 Bearer Token 鉴权（凭证在 Authorization 请求头，非 URL/body），失败响应体形如
 * `{errors:[{field?,reason}]}`。LinodeClient 把「HTTP 非 2xx」或「响应体带 errors」归一为本异常：
 * 携带 HTTP 状态码 + 拼接后的 reason（取自响应体 errors，不含凭证）。
 * LinodeErrorSanitizer 据此类型只取 code + 安全描述。
 */
class LinodeApiException extends RuntimeException
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
