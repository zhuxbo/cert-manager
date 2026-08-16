<?php

namespace Plugins\CloudDeploy\Deployers\Samwaf;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/** SamWaf 管理面板自身的 HTTPS 证书。 */
class SamwafConsoleDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'samwaf';
    }

    public function product(): string
    {
        return 'console';
    }

    public function label(): string
    {
        return 'SamWaf 管理面板';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'auto_restart', 'label' => '部署后自动重启', 'type' => 'boolean', 'required' => false, 'default' => true],
        ];
    }

    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        if (! is_array($certRef)) {
            $this->fail('SamWaf Console 需要内联证书材料');
        }
        $fullChain = rtrim((string) ($certRef['cert'] ?? ''));
        if (trim((string) ($certRef['chain'] ?? '')) !== '') {
            $fullChain .= "\n".trim((string) $certRef['chain']);
        }
        $privateKey = (string) ($certRef['key'] ?? '');
        $autoRestart = $this->truthy($config['auto_restart'] ?? true);

        $this->guardSdk(function () use ($credentials, $fullChain, $privateKey, $autoRestart): void {
            /** @var SamwafClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->uploadConsoleCertificate($fullChain, $privateKey);
            $client->enableConsoleSsl();
            if ($autoRestart) {
                $client->restartConsoleManager();
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $serverUrl = rtrim((string) ($credentials['server_url'] ?? ''), '/');

        return match ($kind) {
            'api' => new SamwafClient($this->outboundHttpClient("$serverUrl/api/v1/", [
                'timeout' => 30,
                'verify' => empty($credentials['allow_insecure']),
                'headers' => [
                    'X-API-Key' => (string) ($credentials['api_key'] ?? ''),
                    'Accept' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return SamwafErrorSanitizer::sanitize($e);
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
