<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanelgo;

use RuntimeException;

/**
 * 宝塔面板（Windows Go 版）API REST 接口错误（区别于本地/网络层异常）。
 *
 * 与 Linux 宝塔同签名机制（api_sk + request_time MD5，request_token 表单字段）。响应体形如
 * `{status, code, msg, data}`——status 可能是 bool 或 int（0 成功），非成功归一为本异常：携带
 * 'BaotaError' + 自带 msg（取自响应体，不含 apiKey）。BaotapanelgoErrorSanitizer 据此类型只取 code + 描述。
 */
class BaotapanelgoApiException extends RuntimeException
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
