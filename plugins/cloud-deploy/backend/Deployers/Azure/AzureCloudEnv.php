<?php

namespace Plugins\CloudDeploy\Deployers\Azure;

/**
 * Azure 主权云环境配置（对齐 certimate xazure.GetCloudEnvConfiguration / IsChinaEnv / IsUSGovernmentEnv）。
 *
 * 不同主权云的 AAD 登录端点、Key Vault scope、Key Vault DNS 后缀均不同：
 *   - AzurePublic（默认）：login.microsoftonline.com / vault.azure.net
 *   - AzureChina（世纪互联）：login.chinacloudapi.cn / vault.azure.cn
 *   - AzureUSGovernment：login.microsoftonline.us / vault.usgovcloudapi.net
 *
 * cloudName 不区分大小写，兼容 certimate 接受的别名。
 */
class AzureCloudEnv
{
    /** @return array{login:string,vaultScope:string,vaultDnsSuffix:string} */
    public static function resolve(string $cloudName): array
    {
        $name = strtolower(trim($cloudName));

        if (self::isChina($name)) {
            return [
                'login' => 'https://login.chinacloudapi.cn',
                'vaultScope' => 'https://vault.azure.cn/.default',
                'vaultDnsSuffix' => 'vault.azure.cn',
            ];
        }

        if (self::isUSGovernment($name)) {
            return [
                'login' => 'https://login.microsoftonline.us',
                'vaultScope' => 'https://vault.usgovcloudapi.net/.default',
                'vaultDnsSuffix' => 'vault.usgovcloudapi.net',
            ];
        }

        return [
            'login' => 'https://login.microsoftonline.com',
            'vaultScope' => 'https://vault.azure.net/.default',
            'vaultDnsSuffix' => 'vault.azure.net',
        ];
    }

    private static function isChina(string $name): bool
    {
        return in_array($name, ['china', 'chinacloud', 'azurechina', 'azurechinacloud'], true);
    }

    private static function isUSGovernment(string $name): bool
    {
        return in_array($name, ['usgovernment', 'usgov', 'usgovernmentcloud', 'azureusgovernment', 'azureusgovernmentcloud'], true);
    }
}
