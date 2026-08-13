<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

final class OraclecloudAuthMaterial
{
    public function __construct(
        private readonly string $region,
        private readonly string $keyId,
        private readonly string $privateKey,
        private readonly string $privateKeyPassphrase = '',
        private readonly ?int $expiresAt = null,
    ) {}

    public static function apiKey(
        string $tenancyOcid,
        string $userOcid,
        string $fingerprint,
        string $privateKey,
        string $privateKeyPassphrase,
        string $region,
    ): self {
        return new self(
            $region,
            $tenancyOcid.'/'.$userOcid.'/'.$fingerprint,
            $privateKey,
            $privateKeyPassphrase,
        );
    }

    public static function securityToken(
        string $token,
        string $privateKey,
        string $region,
        string $privateKeyPassphrase = '',
        ?int $expiresAt = null,
    ): self {
        return new self($region, 'ST$'.$token, $privateKey, $privateKeyPassphrase, $expiresAt);
    }

    public function region(): string
    {
        return $this->region;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function privateKey(): string
    {
        return $this->privateKey;
    }

    public function privateKeyPassphrase(): string
    {
        return $this->privateKeyPassphrase;
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }
}
