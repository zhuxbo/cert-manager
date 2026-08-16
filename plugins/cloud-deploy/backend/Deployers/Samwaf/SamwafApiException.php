<?php

namespace Plugins\CloudDeploy\Deployers\Samwaf;

use RuntimeException;

/**
 * SamWaf 接口错误（区别于本地/网络层异常）。
 *
 * SamWaf API（base {serverUrl}/api/v1）走 `X-API-Key` 头鉴权。响应体形如 `{code, msg, data}`：
 * `code==0` 成功、非 0 失败。SamwafClient 把 HTTP、业务和畸形响应归一为固定本地错误码与文案，
 * 不采信上游 code/msg，避免设备回显 API Key 等不透明敏感值。
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
