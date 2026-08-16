<?php

namespace Plugins\CloudDeploy\Deployers\Huaweiibmc;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ConsoleDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'huaweiibmc';
    }

    public function product(): string
    {
        return 'console';
    }

    public function label(): string
    {
        return 'Huawei iBMC 控制台';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'auto_restart', 'label' => '导入后自动重启', 'type' => 'bool', 'required' => false, 'default' => true],
        ];
    }

    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        if (! is_array($certRef)) {
            $this->fail('Huawei iBMC 需要内联证书材料');
        }

        $password = bin2hex(random_bytes(24));
        $pfx = $this->toPkcs12((string) ($certRef['cert'] ?? ''), (string) ($certRef['key'] ?? ''), (string) ($certRef['chain'] ?? ''), $password);
        $autoRestart = $this->truthy($config['auto_restart'] ?? true);

        $this->guardSdk(function () use ($credentials, $password, $pfx, $autoRestart): void {
            /** @var HuaweiibmcClient $client */
            $client = $this->makeClient('api', $credentials);
            $sessionCreated = false;
            try {
                $client->createSession((string) ($credentials['username'] ?? ''), (string) ($credentials['password'] ?? ''));
                $sessionCreated = true;
                foreach ($client->listManagers() as $manager) {
                    $location = (string) ($manager['@odata.id'] ?? '');
                    if ($location === '') {
                        $id = (string) ($manager['Id'] ?? '');
                        if ($id === '') {
                            throw new RuntimeException('iBMC Manager 缺少 Id');
                        }
                        $location = '/redfish/v1/Managers/'.rawurlencode($id);
                    }

                    $client->importCustomCertificate($location, [
                        'Certificate' => base64_encode($pfx),
                        'Password' => $password,
                    ]);
                    if ($autoRestart) {
                        $client->resetManager($location, 'ForceRestart');
                    }
                }
            } finally {
                if ($sessionCreated) {
                    try {
                        $client->deleteSession();
                    } catch (Throwable) {
                        // 会话清理失败不能覆盖主部署结果，且不得把 token 带入异常。
                    }
                }
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $baseUri = $this->normalizeBaseUri((string) ($credentials['host'] ?? ''));

        return match ($kind) {
            'api' => new HuaweiibmcClient($this->outboundHttpClient($baseUri, [
                'timeout' => 30,
                'verify' => ! $this->truthy($credentials['allow_insecure_connections'] ?? false),
                'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            ]), $baseUri),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweiibmcErrorSanitizer::sanitize($e);
    }

    private function normalizeBaseUri(string $host): string
    {
        $host = trim($host);
        if (! str_contains($host, '://')) {
            $literal = trim($host, '[]');
            if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $host = "[$literal]";
            }
            $host = 'https://'.$host;
        }

        return rtrim($host, '/').'/';
    }

    private function toPkcs12(string $certPem, string $keyPem, string $chainPem, string $password): string
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certPem."\n".$chainPem, $matches);
        $certificates = $matches[0];
        if ($certificates === []) {
            throw new RuntimeException('Huawei iBMC 证书转换失败：未找到证书');
        }

        $leafFile = tempnam(sys_get_temp_dir(), 'clouddeploy_ibmc_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'clouddeploy_ibmc_key_');
        $chainFile = count($certificates) > 1 ? tempnam(sys_get_temp_dir(), 'clouddeploy_ibmc_chain_') : null;
        $outputFile = tempnam(sys_get_temp_dir(), 'clouddeploy_ibmc_pfx_');
        if ($leafFile === false || $keyFile === false || $outputFile === false || (count($certificates) > 1 && $chainFile === false)) {
            throw new RuntimeException('Huawei iBMC 证书转换失败：无法创建临时文件');
        }

        try {
            if (file_put_contents($leafFile, $certificates[0]) === false || file_put_contents($keyFile, $keyPem) === false) {
                throw new RuntimeException('Huawei iBMC 证书转换失败：无法写入临时文件');
            }
            chmod($leafFile, 0600);
            chmod($keyFile, 0600);
            chmod($outputFile, 0600);

            $command = [
                'openssl', 'pkcs12', '-export', '-legacy',
                '-in', $leafFile,
                '-inkey', $keyFile,
                '-out', $outputFile,
                '-passout', 'env:CLOUDDEPLOY_IBMC_PFX_PASSWORD',
            ];
            if (is_string($chainFile)) {
                if (file_put_contents($chainFile, implode("\n", array_slice($certificates, 1))) === false) {
                    throw new RuntimeException('Huawei iBMC 证书转换失败：无法写入证书链');
                }
                chmod($chainFile, 0600);
                array_push($command, '-certfile', $chainFile);
            }

            $process = new Process($command, null, ['CLOUDDEPLOY_IBMC_PFX_PASSWORD' => $password]);
            $process->setTimeout(30);
            $process->run();
            $pfx = is_file($outputFile) ? file_get_contents($outputFile) : false;
            if (! $process->isSuccessful() || ! is_string($pfx) || $pfx === '') {
                throw new RuntimeException('Huawei iBMC Legacy PFX 转换失败');
            }

            return $pfx;
        } finally {
            @unlink($leafFile);
            @unlink($keyFile);
            if (is_string($chainFile)) {
                @unlink($chainFile);
            }
            @unlink($outputFile);
        }
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
