<?php

namespace Plugins\CloudDeploy\Deployers\Baishan;

use RuntimeException;

/**
 * 白山云 REST 接口错误（区别于本地/网络层异常）。
 *
 * 白山云无官方 PHP SDK，CDN 走自写 REST（BaishanRestClient），无现成 SDK 异常类型可 catch。
 * BaishanRestClient 把「HTTP 非 2xx」或「响应体 code 非 0（白山云统一响应体 {code,message,data}）」
 * 归一为本异常：携带白山云错误码（响应体 code，或 HTTP 状态码兜底）+ 白山云自带 message 描述。
 *
 * 脱敏说明：白山云鉴权走 URL 查询串里的 token={apiToken}，故 token 可能出现在请求 URL 中（网络异常 message
 * 里会带出），但**响应体的 code/message 不含 token**，安全。BaishanErrorSanitizer 对结构化错误取 code+message、
 * 对网络错误只暴露类名，并走 CredentialScrubber 兜底。
 */
class BaishanApiException extends RuntimeException
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
