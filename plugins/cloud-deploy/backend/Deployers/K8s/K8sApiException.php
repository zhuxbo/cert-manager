<?php

namespace Plugins\CloudDeploy\Deployers\K8s;

use RuntimeException;

/**
 * Kubernetes API Server REST 接口错误（区别于本地/网络层异常）。
 *
 * Kubernetes API 走 Bearer Token 鉴权（凭证在 Authorization 请求头，非 URL/body），错误响应体形如
 * `{kind:"Status", status:"Failure", message, reason, code}`（meta.k8s.io/v1 Status 对象）。
 * K8sClient 把「HTTP 非 2xx 且非 404-视为不存在」归一为本异常：携带 K8s reason/HTTP 状态码 + 自带
 * message（取自响应体 Status.message，不含 token）。K8sErrorSanitizer 据此类型只取 code + 安全描述。
 */
class K8sApiException extends RuntimeException
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
