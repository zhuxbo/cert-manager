<?php

namespace Plugins\CloudDeploy\Deployers\Unicloud;

use RuntimeException;

/**
 * uniCloud REST 接口错误（区别于本地/网络层异常）。
 *
 * uniCloud（DCloud）走「账号 + 密码」登录换 serverless token → apiUser token 两级鉴权（凭证在登录请求体、
 * token 在请求头，非 URL）。两类响应体：① serverless invoke 返回 `{success, error:{code,message}}`；
 * ② apiUser 请求返回 `{ret, desc}`。UnicloudClient 把失败归一为本异常：携带错误码 + 自带描述
 * （取自响应体，不含凭证）。UnicloudErrorSanitizer 据此类型只取 code + 安全描述。
 */
class UnicloudApiException extends RuntimeException
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
