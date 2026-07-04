<?php

namespace Plugins\CloudDeploy\Deployers\Baidu;

use BaiduBce\Exception\BceServiceException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 百度智能云 BCE SDK 异常脱敏（共用于所有百度 deployer/uploader）。
 *
 * 泄露面分析（baidubce/bce-sdk-php）：
 * - BCE V1 签名协议把凭证派生的 Authorization 放在 **请求头**（非 URL 查询串、非 body）。故服务 endpoint /
 *   URL / 请求体本身都不含 AK/SK，签名值也不会出现在异常里。
 * - **BceServiceException**（HTTP 4xx/5xx 结构化错误）：构造自服务端响应体（errorCode/errorMessage/
 *   requestId/statusCode），不含请求与凭证，安全。其 getMessage() 模板为
 *   "{errorMessage} [requestId:.. status:.. code:..]"（亦全为响应体字段），但本类**不**整段回传 getMessage()，
 *   只取 getErrorCode() + getStatusCode() 拼安全文案，杜绝未来若有字段变更带出意外内容。
 * - **BceClientException / 其他 Throwable**（本地/网络错误，message 包底层 Guzzle 异常 message，含请求 URL；
 *   URL 虽无凭证，但出于最小暴露原则）：只暴露类名 + 通用文案，绝不回传 getMessage()。
 *
 * 末尾再过 CredentialScrubber（纵深防御）：万一未来 SDK 把凭证拼进了放行分支，扫 AK/SK/签名/私钥 pattern 拦下。
 */
class BaiduErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof BceServiceException) {
            // 结构化 API 错误：errorCode/statusCode 取自服务端响应，不含请求/凭证。
            $code = $e->getErrorCode();
            $code = is_string($code) && $code !== '' ? $code : 'BaiduError';
            $status = $e->getStatusCode();
            $detail = ($status !== null && $status !== '') ? " (HTTP $status)" : '';

            return "[$code] 百度智能云接口返回错误$detail";
        }

        // 网络/未知错误（含 BceClientException，message 可能含请求 URL）：只暴露类名。
        return '百度智能云调用失败: '.class_basename($e);
    }
}
