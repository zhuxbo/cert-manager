<?php

namespace Plugins\CloudDeploy\Deployers\Goedge;

use RuntimeException;

/**
 * GoEdge 接口错误（区别于本地/网络层异常）。
 *
 * GoEdge API（base {serverUrl}）走登录态 token 鉴权（accessKeyId/accessKey 换 token，token 进
 * `X-Edge-Access-Token` 头）。响应体形如 `{code, message, data}`：`code==200` 成功。GoedgeRestClient
 * 把「HTTP 非 2xx」或「code≠200」归一为本异常：携带 code + message（取自响应体，不含凭证）。
 * GoedgeErrorSanitizer 据此类型只取 code + 描述。
 */
class GoedgeApiException extends RuntimeException
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
