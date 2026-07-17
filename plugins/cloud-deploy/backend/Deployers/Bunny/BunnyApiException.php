<?php

namespace Plugins\CloudDeploy\Deployers\Bunny;

use RuntimeException;

/**
 * Bunny REST 接口错误（区别于本地/网络层异常）。
 *
 * Bunny API 走 AccessKey 请求头鉴权（凭证在 AccessKey 请求头，非 URL/body）。certimate 的 SDK 仅按
 * HTTP 状态码判失败、不解析结构化错误体；本客户端在归一时尽力取响应体的 Message/message 作描述
 * （Bunny 实际多返回 `{Message}`），无则回落 HTTP 状态。错误码用 HTTP 状态（Bunny 无业务错误码）。
 * BunnyErrorSanitizer 据此类型只取 code + 安全描述。
 */
class BunnyApiException extends RuntimeException
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
