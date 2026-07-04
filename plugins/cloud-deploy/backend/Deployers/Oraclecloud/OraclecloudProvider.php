<?php

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Oracle Cloud（OCI）provider。
 *
 * 凭证对齐 certimate AccessConfigForOracleCloud（tenancyOcid/userOcid/fingerprint/privateKey）+ region：
 * certimate 的 OCI SDK 从配置派生 endpoint，本插件走纯 REST + 手工签名，故 region 必须显式提供（用于
 * 拼 certificatesmanagement.{region}.oci.oraclecloud.com 端点），加入凭证。
 * - tenancy_ocid / user_ocid / fingerprint / private_key：OCI API Key 认证（HTTP Signatures）。
 * - private_key_passphrase：私钥口令（选填）。
 * - region：OCI 区域（如 ap-tokyo-1），决定接口端点。
 */
class OraclecloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'oraclecloud';
    }

    public function label(): string
    {
        return 'Oracle Cloud';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'tenancy_ocid', 'label' => '租户 OCID', 'required' => true],
            ['key' => 'user_ocid', 'label' => '用户 OCID', 'required' => true],
            ['key' => 'fingerprint', 'label' => 'API 公钥指纹', 'required' => true],
            ['key' => 'private_key', 'label' => 'API 私钥（PEM）', 'required' => true, 'secret' => true],
            ['key' => 'private_key_passphrase', 'label' => '私钥口令（选填）', 'required' => false, 'secret' => true],
            ['key' => 'region', 'label' => '区域（如 ap-tokyo-1）', 'required' => true],
        ];
    }
}
