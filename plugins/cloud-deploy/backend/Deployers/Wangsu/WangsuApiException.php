<?php

namespace Plugins\CloudDeploy\Deployers\Wangsu;

use RuntimeException;

/**
 * 网宿云 REST 接口错误（区别于本地/网络层异常）。
 *
 * 网宿云无官方模块化 PHP SDK，故走自写 REST（WangsuRestClient，照 certimate pkg/sdk3rd/wangsu
 * 的 CNC-HMAC-SHA256 签名手写）。WangsuRestClient 把「HTTP 非 2xx」或「响应体 code≠0」归一为本异常：
 * 携带网宿错误码（响应体 code 或 HTTP 状态码）+ 网宿自带的 message 描述（取自响应体 message 字段）。
 *
 * 安全性：网宿签名走 Authorization 请求头（CNC-HMAC-SHA256），AccessKey/SecretKey/apiKey 均不在
 * URL 查询串、不在 body，故响应体的 code/message 是服务端文案、不含凭证。即便如此，
 * WangsuErrorSanitizer 仍只取 code + message 并经 CredentialScrubber 兜底再扫一遍。
 */
class WangsuApiException extends RuntimeException
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
