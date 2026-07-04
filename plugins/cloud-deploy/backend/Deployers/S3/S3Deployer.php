<?php

namespace Plugins\CloudDeploy\Deployers\S3;

use Aws\S3\S3Client;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * S3 兼容对象存储（内联型）：把证书 / 私钥 PEM 文件 PutObject 写入指定 bucket 的对象键。
 *
 * 对齐 certimate s3（FILE_FORMAT_PEM 分支）：用已装 aws/aws-sdk-php 的 S3Client（支持自定义
 * endpoint + path-style，兼容 MinIO / R2 等）写入多个对象键：
 *   - object_key_for_crt        ← 完整证书链（leaf + 中间证书）
 *   - object_key_for_key        ← 私钥
 *   - object_key_for_crt_server ← 仅服务器证书（leaf，选填）
 *   - object_key_for_crt_inter  ← 仅中间证书（chain，选填）
 * 每个对象键非空才写（与 certimate 一致）；至少需配置一个，否则无操作即报错。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组，无云端证书 id。
 * 仅实现 PEM 格式（certimate 另有 PFX/JKS，需证书格式转换二进制，超出纯上传范畴，本插件不实现）。
 */
class S3Deployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 's3';
    }

    public function product(): string
    {
        return 's3';
    }

    public function label(): string
    {
        return 'S3 兼容对象存储';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'bucket', 'label' => '存储桶名', 'type' => 'string', 'required' => true],
            ['key' => 'object_key_for_crt', 'label' => '证书文件对象键（完整链，如 ssl/full.pem）', 'type' => 'string', 'required' => false],
            ['key' => 'object_key_for_key', 'label' => '私钥文件对象键（如 ssl/key.pem）', 'type' => 'string', 'required' => false],
            ['key' => 'object_key_for_crt_server', 'label' => '服务器证书对象键（仅 leaf，选填）', 'type' => 'string', 'required' => false],
            ['key' => 'object_key_for_crt_inter', 'label' => '中间证书对象键（仅中间证书，选填）', 'type' => 'string', 'required' => false],
            ['key' => 'use_path_style', 'label' => '使用路径风格寻址（MinIO/自建 S3 需勾选）', 'type' => 'bool', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{access_key_id:string,secret_access_key:string,region?:string,endpoint?:string}  $credentials
     * @param  array{bucket:string,object_key_for_crt?:string,object_key_for_key?:string,object_key_for_crt_server?:string,object_key_for_crt_inter?:string,use_path_style?:bool}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $bucket = (string) $this->requireConfig($config, 'bucket');
        $crtKey = isset($config['object_key_for_crt']) ? (string) $config['object_key_for_crt'] : '';
        $keyKey = isset($config['object_key_for_key']) ? (string) $config['object_key_for_key'] : '';
        $serverKey = isset($config['object_key_for_crt_server']) ? (string) $config['object_key_for_crt_server'] : '';
        $interKey = isset($config['object_key_for_crt_inter']) ? (string) $config['object_key_for_crt_inter'] : '';

        if ($crtKey === '' && $keyKey === '' && $serverKey === '' && $interKey === '') {
            $this->fail('至少需配置一个对象键（证书或私钥）');
        }

        $serverPem = rtrim($certRef['cert'])."\n";
        $interPem = trim($certRef['chain']) !== '' ? rtrim($certRef['chain'])."\n" : '';
        $fullChain = $serverPem.$interPem;
        $keyPem = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $config, $bucket, $crtKey, $keyKey, $serverKey, $interKey, $fullChain, $keyPem, $serverPem, $interPem) {
            /** @var S3Client $client */
            $client = $this->makeClient('s3', $credentials + ['__use_path_style' => ! empty($config['use_path_style'])]);

            $puts = [
                [$crtKey, $fullChain, 'application/x-pem-file'],
                [$keyKey, $keyPem, 'application/x-pem-file'],
                [$serverKey, $serverPem, 'application/x-pem-file'],
                [$interKey, $interPem, 'application/x-pem-file'],
            ];
            foreach ($puts as [$objectKey, $body, $contentType]) {
                if ($objectKey === '' || $body === '') {
                    continue;
                }
                $client->putObject([
                    'Bucket' => $bucket,
                    'Key' => $objectKey,
                    'Body' => $body,
                    'ContentType' => $contentType,
                ]);
            }
        });
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            's3' => new S3Client(array_filter([
                'version' => 'latest',
                'region' => isset($credentials['region']) && (string) $credentials['region'] !== '' ? (string) $credentials['region'] : 'us-east-1',
                'endpoint' => isset($credentials['endpoint']) && (string) $credentials['endpoint'] !== '' ? (string) $credentials['endpoint'] : null,
                'use_path_style_endpoint' => ! empty($credentials['__use_path_style']),
                'credentials' => [
                    'key' => $credentials['access_key_id'] ?? '',
                    'secret' => $credentials['secret_access_key'] ?? '',
                ],
            ], fn ($v) => $v !== null)),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return S3ErrorSanitizer::sanitize($e);
    }
}
