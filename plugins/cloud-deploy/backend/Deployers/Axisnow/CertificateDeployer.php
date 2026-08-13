<?php

namespace Plugins\CloudDeploy\Deployers\Axisnow;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

class CertificateDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    private const BASE_URI = 'https://api.axisnow.io/client/v1/';

    public function provider(): string
    {
        return 'axisnow';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return 'AxisNow 证书（仅上传）';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function usesRemoteCertStore(): bool
    {
        return false;
    }

    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        if (! is_array($certRef)) {
            $this->fail('AxisNow 需要内联证书材料');
        }

        $uploader = new AxisnowCertUploader(fn (array $uploadCredentials): object => $this->makeClient('api', $uploadCredentials));
        $uploader->upload(
            (string) ($certRef['cert'] ?? ''),
            (string) ($certRef['key'] ?? ''),
            (string) ($certRef['chain'] ?? ''),
            $credentials,
        );
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new AxisnowClient($this->outboundHttpClient(self::BASE_URI, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.($credentials['api_token'] ?? ''),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AxisnowErrorSanitizer::sanitize($e);
    }
}
