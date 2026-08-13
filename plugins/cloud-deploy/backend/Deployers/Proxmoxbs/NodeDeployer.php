<?php

namespace Plugins\CloudDeploy\Deployers\Proxmoxbs;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

class NodeDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'proxmoxbs';
    }

    public function product(): string
    {
        return 'node';
    }

    public function label(): string
    {
        return 'Proxmox Backup Server 节点';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'node_name', 'label' => '节点名称', 'type' => 'string', 'required' => true],
            ['key' => 'auto_restart', 'label' => '上传后自动重启', 'type' => 'bool', 'required' => false, 'default' => true],
        ];
    }

    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        if (! is_array($certRef)) {
            $this->fail('Proxmox BS 需要内联证书材料');
        }

        $nodeName = (string) $this->requireConfig($config, 'node_name');
        $fullChain = trim((string) ($certRef['chain'] ?? '')) === ''
            ? rtrim((string) ($certRef['cert'] ?? ''))
            : rtrim((string) ($certRef['cert'] ?? ''))."\n".trim((string) $certRef['chain']);
        $body = [
            'certificates' => $fullChain,
            'key' => (string) ($certRef['key'] ?? ''),
            'force' => true,
        ];
        if ($this->truthy($config['auto_restart'] ?? true)) {
            $body['restart'] = true;
        }

        $this->guardSdk(function () use ($credentials, $nodeName, $body): void {
            /** @var ProxmoxbsClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->nodeUploadCustomCertificate($nodeName, $body);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $token = (string) ($credentials['api_token'] ?? '');
        $secret = (string) ($credentials['api_token_secret'] ?? '');

        return match ($kind) {
            'api' => new ProxmoxbsClient($this->outboundHttpClient(
                rtrim((string) ($credentials['server_url'] ?? ''), '/').'/api2/json/',
                [
                    'timeout' => 30,
                    'verify' => ! $this->truthy($credentials['allow_insecure_connections'] ?? false),
                    'headers' => [
                        'Authorization' => "PBSAPIToken=$token:$secret",
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ],
            )),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return ProxmoxbsErrorSanitizer::sanitize($e);
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
