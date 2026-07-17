<?php

namespace Plugins\CloudDeploy\Deployers\Baotapanel;

use RuntimeException;

/**
 * 宝塔面板 API REST 接口错误（区别于本地/网络层异常）。
 *
 * 宝塔面板 API 走 api_sk + request_time MD5 签名鉴权（签名经 request_token 表单字段提交，apiKey 不出现在
 * URL）。响应体 v1 形如 `{status:bool, msg}`、v2 形如 `{status:int(0=成功), message:{...}}`。
 * BaotapanelClient 把「HTTP 非 2xx」「status=false（v1）」「status!=0（v2）」归一为本异常：携带
 * 'BaotaError'（宝塔无稳定数字错误码）+ 自带 msg（取自响应体，不含 apiKey）。BaotapanelErrorSanitizer
 * 据此类型只取 code + 安全描述。
 */
class BaotapanelApiException extends RuntimeException
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
