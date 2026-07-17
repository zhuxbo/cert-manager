<?php

namespace Plugins\CloudDeploy\Deployers\S3;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * S3 兼容对象存储 provider（AWS S3 / MinIO / Cloudflare R2 / 其他 S3 兼容服务）。
 *
 * 凭证对齐 certimate AccessConfigForS3（accessKey/secretKey/endpoint）+ region：
 * 用已装 aws/aws-sdk-php 的 S3Client，故凭证用 AWS 风格 access_key_id / secret_access_key，
 * region + endpoint 进凭证（同一账号同 endpoint+region 复用），bucket / 对象键进 target config。
 */
class S3Provider implements ProviderInterface
{
    public function key(): string
    {
        return 's3';
    }

    public function label(): string
    {
        return 'S3 兼容对象存储';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'label' => 'AccessKey ID', 'required' => true],
            ['key' => 'secret_access_key', 'label' => 'SecretAccessKey', 'required' => true, 'secret' => true],
            ['key' => 'region', 'label' => '地域（如 us-east-1；MinIO/R2 任填）', 'required' => true],
            ['key' => 'endpoint', 'label' => '自定义 Endpoint（选填，S3 兼容服务必填）', 'required' => false],
        ];
    }
}
