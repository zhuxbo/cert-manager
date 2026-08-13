<?php

namespace Plugins\CloudDeploy\Deployers\S3;

use App\Services\Binary\BinaryLocator;
use Aws\S3\S3Client;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Plugins\CloudDeploy\Support\SafeHttpClientFactory;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * S3 兼容对象存储（内联型）：把证书 / 私钥 PEM 文件 PutObject 写入指定 bucket 的对象键。
 *
 * 对齐 certimate s3：用已装 aws/aws-sdk-php 的 S3Client（支持自定义
 * endpoint + path-style，兼容 MinIO / R2 等）写入多个对象键：
 *   - object_key_for_crt        ← 完整证书链（leaf + 中间证书）
 *   - object_key_for_key        ← 私钥
 *   - object_key_for_crt_server ← 仅服务器证书（leaf，选填）
 *   - object_key_for_crt_inter  ← 仅中间证书（chain，选填）
 * 每个对象键非空才写（与 certimate 一致）；至少需配置一个，否则无操作即报错。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组，无云端证书 id。
 * 支持 PEM/PFX/JKS；JKS 经 keytool 转换，PFX encoder 映射到 OpenSSL 3 的兼容参数。
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
            ['key' => 'file_format', 'label' => '文件格式（pem/pfx/jks）', 'type' => 'string', 'required' => false],
            ['key' => 'signature_version', 'label' => 'S3 签名版本（选填）', 'type' => 'string', 'required' => false],
            ['key' => 'object_key_for_crt', 'label' => '证书文件对象键（完整链，如 ssl/full.pem）', 'type' => 'string', 'required' => false],
            ['key' => 'object_key_for_key', 'label' => '私钥文件对象键（如 ssl/key.pem）', 'type' => 'string', 'required' => false],
            ['key' => 'object_key_for_crt_server', 'label' => '服务器证书对象键（仅 leaf，选填）', 'type' => 'string', 'required' => false],
            ['key' => 'object_key_for_crt_inter', 'label' => '中间证书对象键（仅中间证书，选填）', 'type' => 'string', 'required' => false],
            ['key' => 'use_path_style', 'label' => '使用路径风格寻址（MinIO/自建 S3 需勾选）', 'type' => 'bool', 'required' => false],
            ['key' => 'pfx_password', 'label' => 'PFX 密码', 'type' => 'string', 'required' => false, 'secret' => true],
            ['key' => 'pfx_encoder', 'label' => 'PFX 编码器（留空使用 OpenSSL 默认）', 'type' => 'string', 'required' => false],
            ['key' => 'jks_alias', 'label' => 'JKS Alias', 'type' => 'string', 'required' => false],
            ['key' => 'jks_keypass', 'label' => 'JKS Key Password', 'type' => 'string', 'required' => false, 'secret' => true],
            ['key' => 'jks_storepass', 'label' => 'JKS Store Password', 'type' => 'string', 'required' => false, 'secret' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{access_key_id:string,secret_access_key:string,region?:string,endpoint?:string,allow_insecure_connections?:mixed}  $credentials
     * @param  array{bucket:string,file_format?:string,signature_version?:string,object_key_for_crt?:string,object_key_for_key?:string,object_key_for_crt_server?:string,object_key_for_crt_inter?:string,use_path_style?:bool,pfx_password?:string,pfx_encoder?:string,jks_alias?:string,jks_keypass?:string,jks_storepass?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $bucket = (string) $this->requireConfig($config, 'bucket');
        $crtKey = isset($config['object_key_for_crt']) ? (string) $config['object_key_for_crt'] : '';
        $keyKey = isset($config['object_key_for_key']) ? (string) $config['object_key_for_key'] : '';
        $serverKey = isset($config['object_key_for_crt_server']) ? (string) $config['object_key_for_crt_server'] : '';
        $interKey = isset($config['object_key_for_crt_inter']) ? (string) $config['object_key_for_crt_inter'] : '';
        $fileFormat = strtolower((string) ($config['file_format'] ?? 'pem'));

        if ($crtKey === '' && ($fileFormat !== 'pem' || ($keyKey === '' && $serverKey === '' && $interKey === ''))) {
            $this->fail('至少需配置一个对象键（证书或私钥）');
        }

        $serverPem = rtrim($certRef['cert'])."\n";
        $interPem = trim($certRef['chain']) !== '' ? rtrim($certRef['chain'])."\n" : '';
        $fullChain = $serverPem.$interPem;
        $keyPem = $certRef['key'];

        if ($fileFormat === 'pfx') {
            $password = (string) $this->requireConfig($config, 'pfx_password');
            $encoder = (string) ($config['pfx_encoder'] ?? '');
            $binary = $this->buildPfx($fullChain, $keyPem, $password, $encoder);
            $puts = [[$crtKey, $binary, 'application/x-pkcs12']];
        } elseif ($fileFormat === 'pem') {
            $puts = [
                [$crtKey, $fullChain, 'application/x-pem-file'],
                [$keyKey, $keyPem, 'application/x-pem-file'],
                [$serverKey, $serverPem, 'application/x-pem-file'],
                [$interKey, $interPem, 'application/x-pem-file'],
            ];
        } elseif ($fileFormat === 'jks') {
            $alias = (string) $this->requireConfig($config, 'jks_alias');
            $keypass = (string) $this->requireConfig($config, 'jks_keypass');
            $storepass = (string) $this->requireConfig($config, 'jks_storepass');
            $binary = $this->buildJks($fullChain, $keyPem, $alias, $keypass, $storepass);
            $puts = [[$crtKey, $binary, 'application/x-java-keystore']];
        } else {
            $this->fail("不支持的文件格式: $fileFormat");
        }

        $this->guardSdk(function () use ($credentials, $config, $bucket, $puts) {
            /** @var S3Client $client */
            $client = $this->makeClient('s3', $credentials + [
                '__use_path_style' => ! empty($config['use_path_style']),
                '__signature_version' => $this->normalizeSignatureVersion((string) ($config['signature_version'] ?? '')),
            ]);
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

    protected function buildPfx(string $certificate, string $privateKey, string $password, string $encoder): string
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificate, $matches);
        $certificates = $matches[0];
        if ($certificates === []) {
            $this->fail('证书转换为 PFX 失败：未找到证书');
        }
        if ($encoder !== '') {
            return $this->buildPfxWithEncoder($certificates, $privateKey, $password, $encoder);
        }
        $output = '';
        $options = count($certificates) > 1 ? ['extracerts' => array_slice($certificates, 1)] : [];
        if (! openssl_pkcs12_export($certificates[0], $output, $privateKey, $password, $options)) {
            $this->fail('证书转换为 PFX 失败');
        }

        return $output;
    }

    /** @param list<string> $certificates */
    private function buildPfxWithEncoder(array $certificates, string $privateKey, string $password, string $encoder): string
    {
        $normalized = strtolower($encoder);
        if (! in_array($normalized, ['legacyrc2', 'legacydes', 'modern2023', 'modern2026'], true)) {
            $this->fail("无效的 PFX encoder: $encoder");
        }
        $openssl = app(BinaryLocator::class)->openssl();
        if ($normalized === 'modern2026') {
            $capabilities = $this->openSslPkcs12Capabilities($openssl);
            if (! $capabilities->supportsModern2026()) {
                $this->fail("Modern2026 需要支持 PBMAC1 的 OpenSSL CLI（{$capabilities->diagnosticVersion()}）。请使用 Modern2023或升级 OpenSSL CLI。");
            }
        }

        $leafFile = null;
        $keyFile = null;
        $chainFile = null;
        $outputFile = null;
        try {
            $leafFile = $this->createPfxTemporaryFile('clouddeploy_pfx_cert_');
            $keyFile = $this->createPfxTemporaryFile('clouddeploy_pfx_key_');
            $chainFile = count($certificates) > 1 ? $this->createPfxTemporaryFile('clouddeploy_pfx_chain_') : null;
            $outputFile = $this->createPfxTemporaryFile('clouddeploy_pfx_out_');
            if (! is_string($leafFile) || ! is_string($keyFile) || ! is_string($outputFile) || (count($certificates) > 1 && ! is_string($chainFile))) {
                $this->fail('证书转换为 PFX 失败：临时文件不可用');
            }
            if (! $this->writePfxTemporaryFile($leafFile, $certificates[0])
                || ! $this->writePfxTemporaryFile($keyFile, $privateKey)
                || ! $this->securePfxTemporaryFile($leafFile)
                || ! $this->securePfxTemporaryFile($keyFile)
                || ! $this->securePfxTemporaryFile($outputFile)) {
                $this->fail('证书转换为 PFX 失败：临时文件不可用');
            }
            $command = [
                $openssl, 'pkcs12', '-export', '-in', $leafFile, '-inkey', $keyFile,
                '-out', $outputFile, '-passout', 'env:CLOUDDEPLOY_PFX_PASSWORD',
            ];
            if (is_string($chainFile)) {
                if (! $this->writePfxTemporaryFile($chainFile, implode("\n", array_slice($certificates, 1)))
                    || ! $this->securePfxTemporaryFile($chainFile)) {
                    $this->fail('证书转换为 PFX 失败：临时文件不可用');
                }
                array_push($command, '-certfile', $chainFile);
            }
            if ($normalized === 'legacyrc2') {
                $command[] = '-legacy';
            } elseif ($normalized === 'legacydes') {
                array_push($command, '-legacy', '-descert');
            } elseif ($normalized === 'modern2026') {
                array_push(
                    $command,
                    '-keypbe', 'AES-256-CBC',
                    '-certpbe', 'AES-256-CBC',
                    '-pbmac1_pbkdf2',
                    '-pbmac1_pbkdf2_md', 'SHA256',
                    '-iter', '2048',
                    '-macsaltlen', '16',
                );
            }

            $successful = $this->runOpenSslPkcs12Export($command, ['CLOUDDEPLOY_PFX_PASSWORD' => $password]);
            $data = is_file($outputFile) ? file_get_contents($outputFile) : false;
            if (! $successful || ! is_string($data) || $data === '') {
                $this->fail("证书转换为 PFX 失败：$encoder 编码不可用");
            }

            return $data;
        } finally {
            $this->removePfxTemporaryFile($leafFile);
            $this->removePfxTemporaryFile($keyFile);
            $this->removePfxTemporaryFile($chainFile);
            $this->removePfxTemporaryFile($outputFile);
        }
    }

    protected function createPfxTemporaryFile(string $prefix): string|false
    {
        return @tempnam($this->pfxTemporaryDirectory(), $prefix);
    }

    protected function pfxTemporaryDirectory(): string
    {
        return sys_get_temp_dir();
    }

    protected function writePfxTemporaryFile(string $path, string $contents): bool
    {
        return @file_put_contents($path, $contents) !== false;
    }

    protected function securePfxTemporaryFile(string $path): bool
    {
        return @chmod($path, 0600);
    }

    protected function removePfxTemporaryFile(?string $path): void
    {
        if (is_string($path)) {
            @unlink($path);
        }
    }

    protected function openSslPkcs12Capabilities(string $binary): OpenSslPkcs12Capabilities
    {
        return OpenSslPkcs12Capabilities::probe($binary);
    }

    /** @param list<string> $command @param array<string, string> $environment */
    protected function runOpenSslPkcs12Export(array $command, array $environment): bool
    {
        try {
            return $this->executeOpenSslPkcs12Export($command, $environment);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $command @param array<string, string> $environment */
    protected function executeOpenSslPkcs12Export(array $command, array $environment): bool
    {
        $process = new Process($command, null, $environment);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful();
    }

    protected function buildJks(string $certificate, string $privateKey, string $alias, string $keypass, string $storepass): string
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificate, $matches);
        $certificates = $matches[0];
        if ($certificates === []) {
            $this->fail('证书转换为 JKS 失败：未找到证书');
        }
        $sourcePass = bin2hex(random_bytes(16));
        $pfx = '';
        $options = ['friendly_name' => $alias];
        if (count($certificates) > 1) {
            $options['extracerts'] = array_slice($certificates, 1);
        }
        if (! openssl_pkcs12_export($certificates[0], $pfx, $privateKey, $sourcePass, $options)) {
            $this->fail('证书转换为 JKS 失败：无法生成中间 PKCS#12');
        }

        $source = tempnam(sys_get_temp_dir(), 'clouddeploy_jks_src_');
        $destination = tempnam(sys_get_temp_dir(), 'clouddeploy_jks_dst_');
        if ($source === false || $destination === false) {
            $this->fail('证书转换为 JKS 失败：无法创建临时文件');
        }
        @unlink($destination);
        try {
            file_put_contents($source, $pfx);
            chmod($source, 0600);
            $process = new Process([
                app(BinaryLocator::class)->keytool(), '-importkeystore', '-noprompt',
                '-srckeystore', $source, '-srcstoretype', 'PKCS12', '-srcalias', $alias,
                '-srcstorepass:env', 'CLOUDDEPLOY_SRC_STOREPASS',
                '-destkeystore', $destination, '-deststoretype', 'JKS', '-destalias', $alias,
                '-deststorepass:env', 'CLOUDDEPLOY_DEST_STOREPASS',
                '-destkeypass:env', 'CLOUDDEPLOY_DEST_KEYPASS',
            ], null, [
                'CLOUDDEPLOY_SRC_STOREPASS' => $sourcePass,
                'CLOUDDEPLOY_DEST_STOREPASS' => $storepass,
                'CLOUDDEPLOY_DEST_KEYPASS' => $keypass,
            ]);
            $process->setTimeout(30);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($destination)) {
                $this->fail('证书转换为 JKS 失败：keytool 执行失败');
            }
            $data = file_get_contents($destination);
            if (! is_string($data) || $data === '') {
                $this->fail('证书转换为 JKS 失败：输出为空');
            }

            return $data;
        } finally {
            @unlink($source);
            @unlink($destination);
        }
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials): object
    {
        $endpoint = isset($credentials['endpoint']) && (string) $credentials['endpoint'] !== ''
            ? (string) $credentials['endpoint']
            : null;
        $httpOptions = null;

        if ($endpoint !== null) {
            $destination = app(OutboundDestinationPolicy::class)->authorize($this->provider(), $endpoint);
            $endpoint = $destination->url;
            $httpOptions = app(SafeHttpClientFactory::class)->optionsFor($destination);
            if ($this->truthy($credentials['allow_insecure_connections'] ?? false)) {
                $httpOptions['verify'] = false;
            }
        }

        return match ($kind) {
            's3' => new S3Client(array_filter([
                'version' => 'latest',
                'signature_version' => isset($credentials['__signature_version']) && $credentials['__signature_version'] !== ''
                    ? (string) $credentials['__signature_version'] : null,
                'region' => isset($credentials['region']) && (string) $credentials['region'] !== '' ? (string) $credentials['region'] : 'us-east-1',
                'endpoint' => $endpoint,
                'http' => $httpOptions,
                'use_path_style_endpoint' => ! empty($credentials['__use_path_style']),
                'credentials' => [
                    'key' => $credentials['access_key_id'] ?? '',
                    'secret' => $credentials['secret_access_key'] ?? '',
                ],
            ], fn ($v) => $v !== null)),
            default => throw new \LogicException("未知的 S3 SDK 客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return S3ErrorSanitizer::sanitize($e);
    }

    private function truthy(mixed $value): bool
    {
        return is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeSignatureVersion(string $version): string
    {
        return match (strtolower($version)) {
            '' => '',
            'v2', 's3' => 's3',
            'v4', 's3v4' => 's3v4',
            default => $this->fail("不支持的 S3 签名版本: $version"),
        };
    }
}
