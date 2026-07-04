<?php

namespace Plugins\CloudDeploy\Deployers\Googlecloud;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * Google Cloud provider。
 *
 * 凭证对齐 certimate AccessConfigForGoogleCloud（projectId + serviceAccountKey）：
 * - credentials_json：service account 密钥 JSON 原文（含 client_email / private_key，self-signed JWT 用）。
 * - project：GCP 项目 ID（选填，缺省时从 service account JSON 的 project_id 派生）。
 */
class GooglecloudProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'googlecloud';
    }

    public function label(): string
    {
        return 'Google Cloud';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'credentials_json', 'label' => '服务账号密钥 JSON', 'required' => true, 'secret' => true],
            ['key' => 'project', 'label' => '项目 ID（选填，缺省取密钥内 project_id）', 'required' => false],
        ];
    }
}
