<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Plugins\CloudDeploy\Support\CloudMetadataHttpClient;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use RuntimeException;
use Throwable;

final class OracleInstancePrincipalProvider implements OraclecloudCredentialProvider
{
    private const REFRESH_WINDOW_SECONDS = 300;

    private readonly ClientInterface $federationClient;

    private readonly Closure $clock;

    private readonly Closure $sessionKeyFactory;

    private readonly Closure $hostAuthorizer;

    private ?OraclecloudAuthMaterial $cached = null;

    public function __construct(
        private readonly CloudMetadataHttpClient $metadata,
        ?ClientInterface $federationClient = null,
        ?Closure $clock = null,
        ?Closure $sessionKeyFactory = null,
        ?Closure $hostAuthorizer = null,
    ) {
        $this->federationClient = $federationClient ?? new Client;
        $this->clock = $clock ?? static fn (): int => time();
        $this->sessionKeyFactory = $sessionKeyFactory ?? self::defaultSessionKeyFactory(...);
        $this->hostAuthorizer = $hostAuthorizer ?? static fn (string $host): mixed => app(OutboundDestinationPolicy::class)
            ->authorizeOfficialHost('oraclecloud', $host);
    }

    public function resolve(): OraclecloudAuthMaterial
    {
        $now = ($this->clock)();
        if ($this->cached !== null && ($this->cached->expiresAt() ?? 0) > $now + self::REFRESH_WINDOW_SECONDS) {
            return $this->cached;
        }

        try {
            $region = trim($this->metadata->request(OracleMetadataRoute::Region));
            if (! OracleResourcePrincipalProvider::validRegion($region)) {
                throw new RuntimeException('Invalid OCI region');
            }

            $leafCertificate = $this->metadata->request(OracleMetadataRoute::LeafCertificate);
            $intermediateCertificate = $this->metadata->request(OracleMetadataRoute::IntermediateCertificate);
            $leafPrivateKey = $this->metadata->request(OracleMetadataRoute::PrivateKey);
            [$tenancy, $fingerprint] = $this->certificateIdentity($leafCertificate, $leafPrivateKey);
            [$sessionPrivateKey, $sessionPublicKey] = ($this->sessionKeyFactory)();
            if (! is_string($sessionPrivateKey) || ! is_string($sessionPublicKey)) {
                throw new RuntimeException('Invalid session key material');
            }
            $this->assertKeypair($sessionPrivateKey, $sessionPublicKey);

            $host = 'auth.'.$region.'.oraclecloud.com';
            ($this->hostAuthorizer)($host);
            $path = '/v1/x509';
            $body = json_encode([
                'certificate' => $this->pemBody($leafCertificate),
                'publicKey' => $this->pemBody($sessionPublicKey),
                'intermediateCertificates' => [$this->pemBody($intermediateCertificate)],
                'fingerprintAlgorithm' => 'SHA256',
            ], JSON_THROW_ON_ERROR);
            $federationMaterial = new OraclecloudAuthMaterial(
                $region,
                $tenancy.'/fed-x509-sha256/'.$fingerprint,
                $leafPrivateKey,
            );
            $headers = OciRequestSigner::fromAuthMaterial($federationMaterial)
                ->sign('POST', $host, $path, $body);

            $response = $this->federationClient->request('POST', 'https://'.$host.$path, [
                'headers' => $headers + ['Accept' => 'application/json'],
                'body' => $body,
                'allow_redirects' => false,
                'proxy' => null,
                'connect_timeout' => 2.0,
                'timeout' => 5.0,
                'http_errors' => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('Unexpected federation response');
            }
            $payload = json_decode((string) $response->getBody(), true);
            $token = is_array($payload) && is_string($payload['token'] ?? null)
                ? trim($payload['token'])
                : '';
            $expiresAt = OracleResourcePrincipalProvider::jwtExpiration($token);
            if ($expiresAt === null || $expiresAt <= $now + self::REFRESH_WINDOW_SECONDS) {
                throw new RuntimeException('Invalid federation token');
            }

            return $this->cached = OraclecloudAuthMaterial::securityToken(
                $token,
                $sessionPrivateKey,
                $region,
                '',
                $expiresAt,
            );
        } catch (Throwable) {
            throw new RuntimeException('Oracle instance principal 获取安全令牌失败');
        }
    }

    /** @return array{0:string,1:string} */
    private function certificateIdentity(string $certificatePem, string $privateKeyPem): array
    {
        $certificate = openssl_x509_read($certificatePem);
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($certificate === false || $privateKey === false) {
            throw new RuntimeException('Invalid instance certificate material');
        }
        $parsed = openssl_x509_parse($certificate);
        $subject = is_array($parsed) && is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $tenancy = '';
        array_walk_recursive($subject, static function (mixed $value) use (&$tenancy): void {
            if (is_string($value) && str_starts_with($value, 'opc-tenant:')) {
                $tenancy = substr($value, strlen('opc-tenant:'));
            }
        });
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if ($tenancy === '' || ! is_string($fingerprint) || $fingerprint === '') {
            throw new RuntimeException('Invalid instance certificate identity');
        }

        $certificatePublic = openssl_pkey_get_details(openssl_pkey_get_public($certificate));
        $privateDetails = openssl_pkey_get_details($privateKey);
        if (! is_array($certificatePublic) || ! is_array($privateDetails) || $certificatePublic['key'] !== $privateDetails['key']) {
            throw new RuntimeException('Instance certificate key mismatch');
        }

        return [$tenancy, strtolower($fingerprint)];
    }

    private function pemBody(string $pem): string
    {
        $body = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem);
        if (! is_string($body) || $body === '') {
            throw new RuntimeException('Invalid PEM material');
        }

        return $body;
    }

    private function assertKeypair(string $privateKeyPem, string $publicKeyPem): void
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        $privateDetails = $privateKey === false ? false : openssl_pkey_get_details($privateKey);
        $publicDetails = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        if (! is_array($privateDetails) || ! is_array($publicDetails) || $privateDetails['key'] !== $publicDetails['key']) {
            throw new RuntimeException('Invalid session keypair');
        }
    }

    /** @return array{0:string,1:string} */
    private static function defaultSessionKeyFactory(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false || ! openssl_pkey_export($resource, $privateKey)) {
            throw new RuntimeException('Unable to generate OCI session key');
        }
        $details = openssl_pkey_get_details($resource);
        if (! is_array($details) || ! is_string($details['key'] ?? null)) {
            throw new RuntimeException('Unable to export OCI session public key');
        }

        return [$privateKey, $details['key']];
    }
}
