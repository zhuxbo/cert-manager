<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use Closure;
use RuntimeException;
use Throwable;

final class OracleResourcePrincipalProvider implements OraclecloudCredentialProvider
{
    private const MAX_MATERIAL_BYTES = 65536;

    private readonly Closure $environment;

    private readonly Closure $fileReader;

    private readonly Closure $clock;

    public function __construct(?Closure $environment = null, ?Closure $fileReader = null, ?Closure $clock = null)
    {
        $this->environment = $environment ?? static fn (string $name): string|false => getenv($name);
        $this->fileReader = $fileReader ?? static fn (string $path): string|false => @file_get_contents($path);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function resolve(): OraclecloudAuthMaterial
    {
        try {
            if ($this->environmentValue('OCI_RESOURCE_PRINCIPAL_VERSION') !== '2.2') {
                throw new RuntimeException('Unsupported resource principal version');
            }

            $locations = [
                'region' => $this->environmentValue('OCI_RESOURCE_PRINCIPAL_REGION'),
                'token' => $this->environmentValue('OCI_RESOURCE_PRINCIPAL_RPST'),
                'private_key' => $this->environmentValue('OCI_RESOURCE_PRINCIPAL_PRIVATE_PEM'),
            ];
            $passphraseLocation = $this->optionalEnvironmentValue('OCI_RESOURCE_PRINCIPAL_PRIVATE_PEM_PASSPHRASE');

            $pathMode = $this->isAbsolutePath($locations['region']);
            foreach ($locations as $location) {
                if ($this->isAbsolutePath($location) !== $pathMode) {
                    throw new RuntimeException('Mixed resource principal material mode');
                }
            }
            if ($passphraseLocation !== null && $this->isAbsolutePath($passphraseLocation) !== $pathMode) {
                throw new RuntimeException('Mixed resource principal passphrase mode');
            }

            $region = $pathMode ? $this->readMaterial($locations['region']) : $locations['region'];
            $token = $pathMode ? $this->readMaterial($locations['token']) : $locations['token'];
            $privateKey = $pathMode ? $this->readMaterial($locations['private_key']) : $locations['private_key'];
            $passphrase = $passphraseLocation === null
                ? ''
                : ($pathMode ? $this->readMaterial($passphraseLocation) : $passphraseLocation);

            $region = trim($region);
            $token = trim($token);
            if (! self::validRegion($region)) {
                throw new RuntimeException('Invalid resource principal region');
            }

            $expiresAt = self::jwtExpiration($token);
            if ($expiresAt === null || $expiresAt <= ($this->clock)()) {
                throw new RuntimeException('Invalid resource principal token');
            }
            if (! self::validPrivateKey($privateKey, $passphrase)) {
                throw new RuntimeException('Invalid resource principal private key');
            }

            return OraclecloudAuthMaterial::securityToken(
                $token,
                $privateKey,
                $region,
                $passphrase,
                $expiresAt,
            );
        } catch (Throwable) {
            throw new RuntimeException('Oracle resource principal 凭证无效');
        }
    }

    public static function validRegion(string $region): bool
    {
        return preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $region) === 1;
    }

    public static function jwtExpiration(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[1] === '') {
            return null;
        }

        $payload = strtr($parts[1], '-_', '+/');
        $padding = strlen($payload) % 4;
        if ($padding !== 0) {
            $payload .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }
        $claims = json_decode($decoded, true);
        $expiration = is_array($claims) ? ($claims['exp'] ?? null) : null;

        return is_int($expiration) && $expiration > 0 ? $expiration : null;
    }

    private function environmentValue(string $name): string
    {
        $value = ($this->environment)($name);
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_MATERIAL_BYTES) {
            throw new RuntimeException('Missing resource principal environment value');
        }

        return $value;
    }

    private function optionalEnvironmentValue(string $name): ?string
    {
        $value = ($this->environment)($name);
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || strlen($value) > self::MAX_MATERIAL_BYTES) {
            throw new RuntimeException('Invalid resource principal environment value');
        }

        return $value;
    }

    private function readMaterial(string $path): string
    {
        $value = ($this->fileReader)($path);
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_MATERIAL_BYTES) {
            throw new RuntimeException('Unable to read resource principal material');
        }

        return $value;
    }

    private function isAbsolutePath(string $value): bool
    {
        return str_starts_with($value, '/');
    }

    private static function validPrivateKey(string $privateKey, string $passphrase): bool
    {
        return ($passphrase === ''
            ? openssl_pkey_get_private($privateKey)
            : openssl_pkey_get_private($privateKey, $passphrase)) !== false;
    }
}
