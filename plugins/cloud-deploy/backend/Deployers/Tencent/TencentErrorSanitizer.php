<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use Throwable;

/**
 * 腾讯云 SDK 异常脱敏（共用于所有腾讯云 deployer/uploader）。
 *
 * 泄露面：腾讯 TC3 签名把凭证放 Authorization 请求头（非 URL 查询串、非 body），
 * 故 SDK 自带的 TencentCloudSDKException（code + API message / 响应体）不含 AK/SK，安全。
 * 但为统一防线：仍只取 errorCode + SDK 自身 message，绝不回传非 SDK 异常的原始 message
 * （底层 Guzzle 异常 message 含请求 URI，腾讯虽无查询串凭证，仍以类名替代以最小暴露）。
 */
class TencentErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        // 兜底凭证扫描（纵深防御）：腾讯威胁模型假设凭证在 TC3 请求头、不进 SDK message；
        // 但仍透传 SDK 自身 message，万一上游把凭证拼了进来（边界被破），这里扫 AK/SK/签名/私钥 pattern 拦截。
        return CredentialScrubber::scrub(self::build($e));
    }

    private static function build(Throwable $e): string
    {
        if ($e instanceof TencentCloudSDKException) {
            $code = $e->getErrorCode() !== '' ? $e->getErrorCode() : 'TencentError';
            $msg = $e->getMessage();
            $msg = $msg !== '' ? $msg : '腾讯云接口返回错误';

            return "[$code] $msg";
        }

        return '腾讯云调用失败: '.class_basename($e);
    }
}
