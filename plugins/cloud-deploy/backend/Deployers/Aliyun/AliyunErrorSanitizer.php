<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\Oss\V2\Exception\ServiceException as OssServiceException;
use AlibabaCloud\Tea\Exception\TeaError;
use Darabonba\OpenApi\Exceptions\AlibabaCloudException;
use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

/**
 * 阿里云 SDK 异常脱敏（共用于所有阿里云 deployer）。
 *
 * 泄露面（三套 SDK 体系并存）：
 * - 新一代 openapi-core（apigw/esa 等，client extends Darabonba\OpenApi\OpenApiClient）：结构化 API 错误
 *   （4xx/5xx）→ AlibabaCloudException 子类 ClientException/ServerException/ThrottlingException。其 public
 *   `$code` = 服务端响应体 Code、`$data` = 服务端错误体（含 Code/Message/RequestId），**均来自响应体、不含请求与凭证**，
 *   安全。**注意**：AlibabaCloudException extends DaraException extends TeaError，故必须排在下方 TeaError 分支
 *   之**前**单独识别（否则虽也能命中 TeaError 分支，但语义不显式）。其 getMessage() 为 "code: {状态码},
 *   {服务端 Message} request id: {reqId}" 模板（亦只含响应体字段），但本类仍**不**整段回传 getMessage()，
 *   只取 public $code + 从 $data 取服务端 Message（回落 getDescription()），杜绝未来若有网络类 DaraException
 *   误入父类型时把 Guzzle 签名 URI 带出。新一代**网络/重试耗尽**错误 → DaraUnableRetryException（**非**
 *   AlibabaCloudException 子类），其 message 含 Guzzle 签名 URI → 落入下方「只暴露类名」分支。
 * - 老 darabonba 体系（CDN/Live/VOD/WAF/FC/CAS 等，alibabacloud/tea）：API 错误（4xx/5xx）→ TeaError，其
 *   code/data 来自「响应体」，安全；网络错误 → SDK 把底层 Guzzle 异常 message 包进 TeaError（errorInfo=[]、
 *   data=null），Guzzle 的 message 含完整请求 URI（带 AccessKeyId/Signature 签名查询串）→ 严禁回传。
 * - OSS 独立 SDK（alibabacloud/oss-v2，非 darabonba）：
 *     · ServiceException（4xx/5xx）→ getErrorCode()/getErrorMessage() 取自服务端 XML 错误体，安全；
 *       但其 getMessage() 模板含 getRequestTarget()（请求 URI）→ 不可整段回传，只取 code+errorMessage。
 *     · OperationException（本地/网络失败）→ 把底层 Guzzle 异常链入 $previous 并拼进 getMessage()，
 *       含签名 URI 的 AccessKeyId/Signature → 严禁回传，落入下方「只暴露类名」分支。
 *
 * 故策略：仅当是「结构化 API 错误」（openapi-core AlibabaCloudException、老 darabonba TeaError 且 data 为数组、
 * 或 OSS ServiceException）时，用 code + 服务端错误描述拼安全文案；其余一律只给错误类名 + 通用文案，
 * 绝不回传 getMessage()。
 */
class AliyunErrorSanitizer
{
    /**
     * 仅提取服务端结构化错误码；网络错误及未知异常返回 null。
     */
    public static function errorCode(Throwable $e): ?string
    {
        if ($e instanceof AlibabaCloudException) {
            return is_string($e->code) && $e->code !== '' ? $e->code : null;
        }

        if ($e instanceof TeaError && is_array($e->data)) {
            return is_string($e->code) && $e->code !== '' ? $e->code : null;
        }

        if ($e instanceof OssServiceException) {
            return $e->getErrorCode() !== '' ? $e->getErrorCode() : null;
        }

        return null;
    }

    public static function sanitize(Throwable $e): string
    {
        // 兜底凭证扫描（纵深防御）：精确脱敏后再扫一遍 AK/SK/签名/私钥 pattern，威胁模型边界被破时拦截。
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof AlibabaCloudException) {
            // 新一代 openapi-core 结构化 API 错误：public $code + $data 均取自服务端响应体，不含请求/凭证。
            // 只取 code + 服务端 Message（不整段回传 getMessage()，杜绝潜在 Guzzle URI 泄露）。
            $code = is_string($e->code) && $e->code !== '' ? $e->code : 'AliyunError';
            $msg = (is_array($e->data) ? self::pick($e->data, ['Message', 'message']) : null)
                ?? (is_string($e->description) && $e->description !== '' ? $e->description : null)
                ?? '阿里云接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof TeaError && is_array($e->data)) {
            // 结构化 API 错误：data 是响应体（含 Code/Message/RequestId），不含请求与凭证
            $code = is_string($e->code) && $e->code !== '' ? $e->code : 'AliyunError';
            $msg = self::pick($e->data, ['Message', 'message']) ?? '阿里云接口返回错误';

            return "[$code] $msg";
        }

        if ($e instanceof OssServiceException) {
            // OSS 服务端结构化错误：code/message 取自 XML 错误体，不含请求 URI/凭证
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'AliyunOssError';
            $msg = $e->getErrorMessage() !== '' ? $e->getErrorMessage() : '阿里云 OSS 接口返回错误';

            return "[$code] $msg";
        }

        // 网络/未知错误（含 OSS OperationException，message/previous 可能含签名 URI）：只暴露类名
        return '阿里云调用失败: '.class_basename($e);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  list<string>  $keys
     */
    private static function pick(array $data, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($data[$k]) && is_string($data[$k]) && $data[$k] !== '') {
                return $data[$k];
            }
        }

        return null;
    }
}
