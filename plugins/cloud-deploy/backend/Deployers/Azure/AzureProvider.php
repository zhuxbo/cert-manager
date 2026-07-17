<?php

namespace Plugins\CloudDeploy\Deployers\Azure;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Azure provider。
 *
 * 凭证对齐 certimate AccessConfigForAzure（tenantId/clientId/clientSecret + cloudName）：
 * - tenant_id / client_id / client_secret：service principal（OAuth2 client_credentials）。
 * - cloud_name：主权云环境（选填，默认公有云；可填 china / usgovernment）。
 */
class AzureProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'azure';
    }

    public function label(): string
    {
        return 'Azure';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'tenant_id', 'label' => '租户 ID（Tenant ID）', 'required' => true],
            ['key' => 'client_id', 'label' => '应用 ID（Client ID）', 'required' => true],
            ['key' => 'client_secret', 'label' => '客户端密钥（Client Secret）', 'required' => true, 'secret' => true],
            ['key' => 'cloud_name', 'label' => '主权云环境（选填，默认公有云；china / usgovernment）', 'required' => false],
        ];
    }
}
