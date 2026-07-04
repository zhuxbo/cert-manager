<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use RuntimeException;

/**
 * BytePlus REST 接口错误（区别于本地/网络层异常）。
 *
 * BytePlus 全系列（cdn/alb/clb/apig/certcenter/medialive/tos）走自写 REST（无官方 PHP SDK），
 * 故无现成 SDK 异常类型可 catch。BytePlusRestClient 把两类错误归一为本异常：
 *   - OpenAPI 网关错误：响应体 `ResponseMetadata.Error.{Code,Message}`（火山引擎标准错误体）。
 *   - TOS 错误：响应体 XML/JSON 的 `Code`/`Message`，或 HTTP 状态码。
 *
 * 携带的 errorCode + errorMessage 均取自 **响应体**（不含请求 URI / 凭证 —— volc/TOS 签名都写在
 * Authorization 请求头，URL/body 无凭证）。BytePlusErrorSanitizer 据此类型只取 code + 安全描述。
 *
 * 注意：errorMessage 虽来自响应体（安全），仍由 sanitizer 走 CredentialScrubber 兜底再扫一遍。
 */
class BytePlusApiException extends RuntimeException
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
