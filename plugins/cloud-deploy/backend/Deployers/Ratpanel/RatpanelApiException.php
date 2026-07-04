<?php

namespace Plugins\CloudDeploy\Deployers\Ratpanel;

use RuntimeException;

/**
 * 耗子面板（RatPanel / AcePanel）接口错误（区别于本地/网络层异常）。
 *
 * 耗子面板 API（base {serverUrl}/api）走自定义 HMAC-SHA256 请求签名（accessTokenId + accessToken），
 * 签名进 `Authorization` / `X-Timestamp` 头。响应体形如 `{msg}`：`msg=="success"` 成功。
 * RatpanelRestClient 把「HTTP 非 2xx」或「msg≠success」归一为本异常：携带错误码（HTTP 状态码或 sdk）
 * + 自带 msg（取自响应体，不含凭证）。RatpanelErrorSanitizer 据此类型只取 code + 描述。
 */
class RatpanelApiException extends RuntimeException
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
