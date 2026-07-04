<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use RuntimeException;

/**
 * CAS CertIdentifier 解析（dcdn/vod 证书服务型 deployer 共用）。
 *
 * CertIdentifier 格式 "{certId}-{region}"，其中 certId 为纯数字、region 如 "cn-hangzhou"（含 "-"）。
 * 故按**第一个** "-" 拆分：左为 certId（int），右为 region（可含 "-"），与 certimate SplitN(…, "-", 2) 同义。
 */
trait ParsesCasCertIdentifier
{
    /**
     * @param  string  $certRef  remote_cert_id = CertIdentifier
     * @return array{0:int,1:string} [certId, certRegion]
     */
    protected function parseCertIdentifier(string $certRef): array
    {
        $pos = strpos($certRef, '-');
        if ($pos === false || $pos === 0) {
            throw new RuntimeException("无效的 CertIdentifier: $certRef");
        }

        $certId = substr($certRef, 0, $pos);
        $region = substr($certRef, $pos + 1);
        if (! ctype_digit($certId) || $region === '') {
            throw new RuntimeException("无效的 CertIdentifier: $certRef");
        }

        return [(int) $certId, $region];
    }
}
