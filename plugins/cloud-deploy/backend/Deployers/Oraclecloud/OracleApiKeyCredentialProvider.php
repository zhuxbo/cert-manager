<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

final class OracleApiKeyCredentialProvider implements OraclecloudCredentialProvider
{
    /** @param array<string,mixed> $credentials */
    public function __construct(private readonly array $credentials) {}

    public function resolve(): OraclecloudAuthMaterial
    {
        return OraclecloudAuthMaterial::apiKey(
            (string) ($this->credentials['tenancy_ocid'] ?? ''),
            (string) ($this->credentials['user_ocid'] ?? ''),
            (string) ($this->credentials['fingerprint'] ?? ''),
            (string) ($this->credentials['private_key'] ?? ''),
            (string) ($this->credentials['private_key_passphrase'] ?? ''),
            (string) ($this->credentials['region'] ?? ''),
        );
    }
}
