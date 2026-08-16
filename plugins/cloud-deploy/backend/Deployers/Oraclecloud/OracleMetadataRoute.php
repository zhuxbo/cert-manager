<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use Plugins\CloudDeploy\Support\CloudMetadataRoute;

enum OracleMetadataRoute: string implements CloudMetadataRoute
{
    case Region = 'region';
    case LeafCertificate = 'leaf-certificate';
    case IntermediateCertificate = 'intermediate-certificate';
    case PrivateKey = 'private-key';

    private const BASE_URI = 'http://169.254.169.254';

    public function baseUri(): string
    {
        return self::BASE_URI;
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(array $parameters = []): string
    {
        return match ($this) {
            self::Region => '/opc/v2/instance/region',
            self::LeafCertificate => '/opc/v2/identity/cert.pem',
            self::IntermediateCertificate => '/opc/v2/identity/intermediate.pem',
            self::PrivateKey => '/opc/v2/identity/key.pem',
        };
    }

    public function headers(array $context = []): array
    {
        return ['Authorization' => 'Bearer Oracle'];
    }
}
