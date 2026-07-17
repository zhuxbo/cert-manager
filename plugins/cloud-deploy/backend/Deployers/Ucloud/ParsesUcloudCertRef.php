<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use RuntimeException;

/**
 * UCloud USSL 复合 remote_cert_id 解析（UcloudUsslUploader 构建，ucdn/us3/pathx deployer 共用拆分）。
 *
 * USSL 上传返回数字 CertificateID，而部分端点绑定时需要 certName：
 *   - ucdn：UpdateUcdnDomainHttpsConfigV2 同时要 CertId(数字) + CertName。
 *   - us3：AddUFileSSLCert 要 USSLId(=数字 certId) + CertificateName(=certName)。
 *   - pathx：BindPathXSSL 只要 SSLId(=数字 certId)。
 * CertUploaderInterface::upload 只返回单串，故上传器返回复合串 "{certId}|{certName}"。certId（纯数字）
 * 与 certName（本插件生成的 clouddeploy_{毫秒}，字母/数字/下划线）均不含 "|"，按**第一个** "|" 拆分稳妥。
 */
trait ParsesUcloudCertRef
{
    /** 构建复合 remote_cert_id。 */
    protected function buildCertRef(string $certId, string $certName): string
    {
        return $certId.'|'.$certName;
    }

    /**
     * @param  string  $certRef  remote_cert_id = "{certId}|{certName}"
     * @return array{0:string,1:string} [certId, certName]
     */
    protected function parseCertRef(string $certRef): array
    {
        $pos = strpos($certRef, '|');
        if ($pos === false || $pos === 0) {
            throw new RuntimeException("无效的优刻得 remote_cert_id: $certRef");
        }

        $certId = substr($certRef, 0, $pos);
        $certName = substr($certRef, $pos + 1);
        if ($certId === '' || $certName === '') {
            throw new RuntimeException("无效的优刻得 remote_cert_id: $certRef");
        }

        return [$certId, $certName];
    }
}
