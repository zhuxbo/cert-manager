<?php

namespace Plugins\CloudDeploy\Deployers\Axisnow;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

class AxisnowCertUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'axisnow_certificate';
    }

    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        try {
            /** @var AxisnowClient $client */
            $client = ($this->clientFactory)($credentials);
            $page = 1;
            $perPage = 100;

            do {
                $response = $client->listCertificates($page, $perPage);
                $results = is_array($response['result'] ?? null) ? $response['result'] : [];
                foreach ($results as $certificate) {
                    if (! is_array($certificate) || ! $this->sameCertificate($certPem, (string) ($certificate['certificate'] ?? ''))) {
                        continue;
                    }

                    $uuid = (string) ($certificate['uuid'] ?? '');
                    if ($uuid !== '') {
                        return $uuid;
                    }
                }

                $resultInfo = $response['result_info'] ?? null;
                $hasNextPage = count($results) === $perPage
                    && (! is_array($resultInfo) || $page * $perPage < (int) ($resultInfo['total_count'] ?? 0));
                $page++;
            } while ($hasNextPage);

            $fullChain = trim($chainPem) === '' ? rtrim($certPem) : rtrim($certPem)."\n".trim($chainPem);
            $created = $client->addCertificate([
                'type' => 'upload',
                'name' => 'certimate-'.(int) (microtime(true) * 1000),
                'certificate' => $fullChain,
                'private_key' => $keyPem,
            ]);
            $uuid = (string) ($created['uuid'] ?? '');
            if ($uuid === '') {
                throw new RuntimeException('AxisNow 上传证书未返回 uuid');
            }

            return $uuid;
        } catch (Throwable $e) {
            throw new RuntimeException(AxisnowErrorSanitizer::sanitize($e), 0);
        }
    }

    private function sameCertificate(string $left, string $right): bool
    {
        if ($left === '' || $right === '' || ! function_exists('openssl_x509_fingerprint')) {
            return false;
        }

        $leftFingerprint = @openssl_x509_fingerprint($left, 'sha256');
        $rightFingerprint = @openssl_x509_fingerprint($right, 'sha256');

        return is_string($leftFingerprint)
            && is_string($rightFingerprint)
            && hash_equals(strtolower($leftFingerprint), strtolower($rightFingerprint));
    }
}
