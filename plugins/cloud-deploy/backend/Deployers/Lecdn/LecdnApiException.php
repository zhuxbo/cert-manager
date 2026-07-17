<?php

namespace Plugins\CloudDeploy\Deployers\Lecdn;

use RuntimeException;

/**
 * LeCDN 接口错误（区别于本地/网络层异常）。
 *
 * LeCDN API（base {serverUrl}/prod-api）走账号密码登录换 Bearer token 鉴权。响应体形如 `{code, message|msg, data}`：
 * `code==200` 成功（client 端 message 键为 msg、master 端为 message，本客户端两者都读）。LecdnRestClient
 * 把「HTTP 非 2xx」或「code≠200」归一为本异常：携带 code + 描述（取自响应体，不含凭证）。
 * LecdnErrorSanitizer 据此类型只取 code + 描述。
 */
class LecdnApiException extends RuntimeException
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
