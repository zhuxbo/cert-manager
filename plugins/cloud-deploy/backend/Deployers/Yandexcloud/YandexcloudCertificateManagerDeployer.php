<?php

namespace Plugins\CloudDeploy\Deployers\Yandexcloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

class YandexcloudCertificateManagerDeployer extends AbstractDeployer
{
    private const UPDATE_MASK = 'name,description,labels,certificate,chain,privateKey,deletionProtection';

    public function provider(): string
    {
        return 'yandexcloud';
    }

    public function product(): string
    {
        return 'certificatemanager';
    }

    public function label(): string
    {
        return 'Yandex Cloud 证书管理器';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => '证书 ID（选填；填写时替换该证书）', 'type' => 'string', 'required' => false],
        ];
    }

    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        if (! is_array($certRef)) {
            $this->fail('Yandex Cloud 需要内联证书材料');
        }

        $folderId = trim((string) ($credentials['folder_id'] ?? ''));
        $serviceAccountKey = (string) ($credentials['service_account_key'] ?? '');
        if ($folderId === '' || $serviceAccountKey === '') {
            $this->fail('缺少 Yandex Cloud folder_id / service_account_key');
        }

        $certificateId = trim((string) ($config['certificate_id'] ?? ''));
        $this->guardSdk(function () use ($folderId, $serviceAccountKey, $certificateId, $certRef): void {
            /** @var YandexcloudIamAuthenticator $auth */
            $auth = $this->makeClient('iam', ['service_account_key' => $serviceAccountKey]);
            $iamToken = $auth->fetchIamToken($serviceAccountKey);
            /** @var YandexcloudClient $client */
            $client = $this->makeClient('api', ['iam_token' => $iamToken]);

            if ($certificateId !== '') {
                $existing = $client->getCertificate($certificateId);
                $client->updateCertificate($certificateId, [
                    'updateMask' => self::UPDATE_MASK,
                    'name' => (string) ($existing['name'] ?? ''),
                    'description' => (string) ($existing['description'] ?? ''),
                    'labels' => is_array($existing['labels'] ?? null) ? $existing['labels'] : [],
                    'certificate' => $certRef['cert'],
                    'chain' => $certRef['chain'],
                    'privateKey' => $certRef['key'],
                    'deletionProtection' => (bool) ($existing['deletionProtection'] ?? false),
                ]);

                return;
            }

            $identity = $this->certificateIdentity($certRef['cert']);
            $pageToken = '';
            do {
                $page = $client->listCertificates($folderId, $pageToken);
                foreach ($page['certificates'] as $certificate) {
                    if ($this->sameCertificate($identity, $certificate)) {
                        return;
                    }
                }
                $pageToken = $page['nextPageToken'];
            } while ($pageToken !== '');

            $client->createCertificate([
                'folderId' => $folderId,
                'name' => 'certimate-'.(int) floor(microtime(true) * 1000),
                'description' => 'upload from Certimate',
                'certificate' => $certRef['cert'],
                'chain' => $certRef['chain'],
                'privateKey' => $certRef['key'],
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'iam' => new YandexcloudIamAuthenticator(
                new PhpseclibPs256Signer,
                $this->outboundHttpClient('https://iam.api.cloud.yandex.net/', [
                    'timeout' => 30,
                    'headers' => ['Accept' => 'application/json'],
                ]),
            ),
            'api' => new YandexcloudClient(
                $this->outboundHttpClient('https://certificate-manager.api.cloud.yandex.net/', [
                    'timeout' => 30,
                    'headers' => [
                        'Authorization' => 'Bearer '.(string) ($credentials['iam_token'] ?? ''),
                        'Accept' => 'application/json',
                    ],
                ]),
                $this->outboundHttpClient('https://operation.api.cloud.yandex.net/', [
                    'timeout' => 30,
                    'headers' => [
                        'Authorization' => 'Bearer '.(string) ($credentials['iam_token'] ?? ''),
                        'Accept' => 'application/json',
                    ],
                ]),
            ),
            default => throw new \InvalidArgumentException('Unsupported Yandex Cloud client kind'),
        };
    }

    /** @return array{domains:list<string>,subject:string,issuer:string,serial:string,notBefore:int,notAfter:int} */
    private function certificateIdentity(string $certificate): array
    {
        $parsed = @openssl_x509_parse($certificate);
        if (! is_array($parsed)) {
            throw new YandexcloudApiException('BadRequest', '证书内容无法解析');
        }

        $domains = [];
        $san = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $domains[] = substr($entry, 4);
            }
        }

        $names = (new GoPkixNameFormatter)->fromCertificate($certificate);

        return [
            'domains' => $domains,
            'subject' => $names['subject'],
            'issuer' => $names['issuer'],
            'serial' => strtolower((string) ($parsed['serialNumberHex'] ?? '')),
            'notBefore' => (int) ($parsed['validFrom_time_t'] ?? 0),
            'notAfter' => (int) ($parsed['validTo_time_t'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $identity @param array<string,mixed> $remote */
    private function sameCertificate(array $identity, array $remote): bool
    {
        $domains = is_array($remote['domains'] ?? null) ? array_map('strval', $remote['domains']) : [];

        return strcasecmp(implode(',', $identity['domains']), implode(',', $domains)) === 0
            && $identity['subject'] === (string) ($remote['subject'] ?? '')
            && $identity['issuer'] === (string) ($remote['issuer'] ?? '')
            && strcasecmp($identity['serial'], (string) ($remote['serial'] ?? '')) === 0
            && $this->sameTimestamp($identity['notBefore'], $remote['notBefore'] ?? null)
            && $this->sameTimestamp($identity['notAfter'], $remote['notAfter'] ?? null);
    }

    private function sameTimestamp(int $expected, mixed $actual): bool
    {
        if ($actual === null || $actual === '') {
            return true;
        }
        $timestamp = is_int($actual) ? $actual : strtotime((string) $actual);

        return $timestamp !== false && $expected === $timestamp;
    }

    protected function sanitize(Throwable $e): string
    {
        return YandexcloudErrorSanitizer::sanitize($e);
    }
}
