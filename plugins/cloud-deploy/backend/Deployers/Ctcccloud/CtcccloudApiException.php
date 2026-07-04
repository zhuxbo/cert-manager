<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use RuntimeException;

/**
 * 天翼云 EOP 接口错误（区别于本地/网络层异常）。
 *
 * 天翼云无官方 PHP SDK，全部端点（ao/cdn/cms/elb/faas/icdn/lvdn）走自写 REST（CtcccloudRestClient），
 * 无现成 SDK 异常类型可 catch。CtcccloudRestClient 把「HTTP 非 2xx」或「响应体 statusCode/error 表示业务
 * 失败」归一为本异常：携带天翼云错误码（响应体 statusCode，或 error，或 HTTP 状态码兜底）+ 天翼云自带描述
 * （message/errorMessage/description，均来自响应体）。
 *
 * 脱敏说明：天翼云 EOP 签名走请求头（eop-authorization，HMAC-SHA256），**不回显**在响应体里，故响应体的
 * statusCode/error/message 不含 AccessKeyId/SecretAccessKey/签名，安全。仍由 CtcccloudErrorSanitizer 走
 * CredentialScrubber 兜底再扫一遍。
 */
class CtcccloudApiException extends RuntimeException
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
