<?php

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Oracle Cloud（OCI）provider。
 *
 * 凭证对齐 certimate AccessConfigForOracleCloud 的三种认证方式：API Key、instance principal、
 * resource principal。API Key 继续显式配置 region；两种 principal 分别从 OCI metadata 或 2.2 环境材料
 * 解析 region，不把平台临时凭证写入 access。
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
            [
                'key' => 'auth_method',
                'label' => '认证方式',
                'type' => 'select',
                'default' => 'apikey',
                'options' => [
                    ['label' => 'API Key', 'value' => 'apikey'],
                    ['label' => '实例主体（Instance Principal）', 'value' => 'instanceprincipal'],
                    ['label' => '资源主体（Resource Principal 2.2）', 'value' => 'resourceprincipal'],
                ],
            ],
            $this->apiKeyField('tenancy_ocid', '租户 OCID'),
            $this->apiKeyField('user_ocid', '用户 OCID'),
            $this->apiKeyField('fingerprint', 'API 公钥指纹'),
            $this->apiKeyField('private_key', 'API 私钥（PEM）', true),
            [
                'key' => 'private_key_passphrase',
                'label' => '私钥口令（选填）',
                'visible_when' => ['key' => 'auth_method', 'equals' => 'apikey'],
                'secret' => true,
            ],
            $this->apiKeyField('region', '区域（如 ap-tokyo-1）'),
        ];
    }

    /** @return array<string,mixed> */
    private function apiKeyField(string $key, string $label, bool $secret = false): array
    {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'required_when' => ['key' => 'auth_method', 'equals' => 'apikey'],
            'visible_when' => ['key' => 'auth_method', 'equals' => 'apikey'],
            'secret' => $secret ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
