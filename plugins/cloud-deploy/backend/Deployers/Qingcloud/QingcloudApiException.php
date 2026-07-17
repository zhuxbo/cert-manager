<?php

namespace Plugins\CloudDeploy\Deployers\Qingcloud;

use RuntimeException;

/**
 * 青云 IaaS REST 接口错误（区别于本地/网络层异常）。
 *
 * 青云 LB 走老版 IaaS OpenAPI（QY 签名），certimate 用 yunify/qingcloud-sdk-go 调用。本插件照其
 * 引用的 REST 协议自写 QingcloudRestClient，无现成 SDK 异常类型可 catch。QingcloudRestClient 把
 * 「HTTP 非 2xx」或「响应体 ret_code 非 0（青云 IaaS 统一响应体 {ret_code,message}）」归一为本异常：
 * 携带青云 ret_code（或 HTTP 状态码兜底）+ 青云自带 message 描述。
 *
 * 脱敏说明：青云 QY 签名以 signature 字段塞进请求 query/form，**不回显**在响应里，故响应体的
 * ret_code/message 不含 access_key_id / secret_access_key / signature，安全。仍由 QingcloudErrorSanitizer
 * 走 CredentialScrubber 兜底。
 */
class QingcloudApiException extends RuntimeException
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
