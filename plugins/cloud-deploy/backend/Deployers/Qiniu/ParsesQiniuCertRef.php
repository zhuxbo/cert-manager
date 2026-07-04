<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use RuntimeException;

/**
 * 七牛复合 remote_cert_id 解析（QiniuSslUploader 构建，cdn/kodo/pili deployer 共用拆分）。
 *
 * 七牛 cdn/kodo 绑定用 certID、pili 绑定用 certName，而 CertUploaderInterface::upload 只返回单串，
 * 故上传器返回复合串 "{certID}|{certName}"。certID（接口返回的 hex 串）与 certName（本插件生成的
 * clouddeploy_{毫秒}，字母/数字/下划线）均不含 "|"，按**第一个** "|" 拆分稳妥。
 */
trait ParsesQiniuCertRef
{
    /** 构建复合 remote_cert_id。 */
    protected function buildCertRef(string $certId, string $certName): string
    {
        return $certId.'|'.$certName;
    }

    /**
     * @param  string  $certRef  remote_cert_id = "{certID}|{certName}"
     * @return array{0:string,1:string} [certId, certName]
     */
    protected function parseCertRef(string $certRef): array
    {
        $pos = strpos($certRef, '|');
        if ($pos === false || $pos === 0) {
            throw new RuntimeException("无效的七牛 remote_cert_id: $certRef");
        }

        $certId = substr($certRef, 0, $pos);
        $certName = substr($certRef, $pos + 1);
        if ($certId === '' || $certName === '') {
            throw new RuntimeException("无效的七牛 remote_cert_id: $certRef");
        }

        return [$certId, $certName];
    }
}
