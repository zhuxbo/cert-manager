<?php

namespace Plugins\CloudDeploy\Deployers\Upyun;

use RuntimeException;

/**
 * 又拍云控制台 REST 接口错误（区别于本地/网络层异常）。
 *
 * 又拍云控制台 API（base https://console.upyun.com）走账号登录 Cookie 鉴权（凭证仅出现在登录请求 body，
 * 业务接口靠 Cookie 头），响应体形如 `{data:{error_code, message, result}}`。UpyunRestClient 把
 * 「HTTP 非 2xx」「无 data」或「data.error_code≠0」归一为本异常：携带又拍云错误码（业务 error_code 或
 * HTTP 状态码）+ 又拍云自带 message（取自响应体，不含 username/password）。UpyunErrorSanitizer 据此
 * 类型只取 code + 安全描述。
 */
class UpyunApiException extends RuntimeException
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
