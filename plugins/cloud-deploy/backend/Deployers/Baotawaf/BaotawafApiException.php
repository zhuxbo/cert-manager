<?php

namespace Plugins\CloudDeploy\Deployers\Baotawaf;

use RuntimeException;

/**
 * 堡塔云 WAF API REST 接口错误（区别于本地/网络层异常）。
 *
 * 堡塔云 WAF API 走 api_sk + waf_request_time MD5 签名鉴权（签名经 waf_request_token 请求头提交，apiKey
 * 不出现在 URL/body）。响应体形如 `{code, ...}`，code != 0 视为错误。BaotawafClient 把「HTTP 非 2xx」或
 * 「code != 0」归一为本异常：携带 WAF 业务码（数字）+ 通用描述（堡塔 WAF 错误体多无稳定 message，仅暴露
 * code，不含 apiKey）。BaotawafErrorSanitizer 据此类型只取 code + 安全描述。
 */
class BaotawafApiException extends RuntimeException
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
