<?php

namespace Plugins\CloudDeploy\Deployers\Onepanel;

use RuntimeException;

/**
 * 1Panel API REST 接口错误（区别于本地/网络层异常）。
 *
 * 1Panel API 走 api-key + 时间戳 MD5 签名鉴权（签名在 1Panel-Token 请求头，apiKey 不出现在 URL/body），
 * 响应体形如 `{code, message, data}`，code/100 != 2 视为错误。OnepanelClient 把「HTTP 非 2xx」或
 * 「code/100 != 2」归一为本异常：携带 1Panel 业务码 + 自带 message（取自响应体，不含 apiKey）。
 * OnepanelErrorSanitizer 据此类型只取 code + 安全描述。
 */
class OnepanelApiException extends RuntimeException
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
