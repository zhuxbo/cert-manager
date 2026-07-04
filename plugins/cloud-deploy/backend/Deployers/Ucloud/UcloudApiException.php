<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use RuntimeException;

/**
 * 优刻得（UCloud）REST 接口错误（区别于本地/网络层异常）。
 *
 * UCloud 各服务（USSL/ULB/UCDN/UEWAF/PathX/UFile）无官方模块化 PHP SDK，故走自写 REST
 * （UcloudRestClient，照 ucloud-sdk-go 的公钥/私钥签名手写）。UcloudRestClient 把
 * 「HTTP 非 2xx」或「响应体 RetCode≠0」归一为本异常：携带 UCloud 错误码（RetCode 或 HTTP
 * 状态码）+ UCloud 自带的 Message 描述（取自响应体 Message 字段）。
 *
 * 安全性：UCloud 签名走请求体里的 Signature 参数（POST form-urlencoded body），不在 URL；
 * 响应体的 RetCode/Message 是服务端文案，不含 PublicKey/PrivateKey/Signature。即便如此，
 * UcloudErrorSanitizer 仍只取 code + Message 并经 CredentialScrubber 兜底再扫一遍。
 */
class UcloudApiException extends RuntimeException
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
