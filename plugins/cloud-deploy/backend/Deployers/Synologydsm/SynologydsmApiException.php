<?php

namespace Plugins\CloudDeploy\Deployers\Synologydsm;

use RuntimeException;

/**
 * 群晖 DSM WebAPI 接口错误（区别于本地/网络层异常）。
 *
 * 群晖 DSM 走「登录拿 sid + SynoToken」鉴权（sid/SynoToken 随 URL 查询串 _sid/SynoToken 与
 * X-SYNO-TOKEN 头回送），响应体形如 `{success:false, error:{code}}`（错误时仅含数字 code，无入参回显）。
 * SynologydsmClient 把「HTTP 非 2xx」或「success=false」归一为本异常：携带错误码 + 可读描述
 * （来自内置 code→desc 映射，不含账号/密码/sid/token）。SynologydsmErrorSanitizer 据此类型只取 code + 描述。
 */
class SynologydsmApiException extends RuntimeException
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
