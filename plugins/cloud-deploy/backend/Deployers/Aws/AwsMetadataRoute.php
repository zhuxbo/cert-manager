<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Deployers\Aws;

use LogicException;
use Plugins\CloudDeploy\Support\CloudMetadataRoute;

enum AwsMetadataRoute: string implements CloudMetadataRoute
{
    case Token = 'token';
    case RoleList = 'role-list';
    case RoleCredentials = 'role-credentials';

    private const BASE_URI = 'http://169.254.169.254';

    public function baseUri(): string
    {
        return self::BASE_URI;
    }

    public function method(): string
    {
        return $this === self::Token ? 'PUT' : 'GET';
    }

    public function path(array $parameters = []): string
    {
        return match ($this) {
            self::Token => '/latest/api/token',
            self::RoleList => '/latest/meta-data/iam/security-credentials/',
            self::RoleCredentials => '/latest/meta-data/iam/security-credentials/'.$this->roleName($parameters),
        };
    }

    public function headers(array $context = []): array
    {
        if ($this === self::Token) {
            return ['X-aws-ec2-metadata-token-ttl-seconds' => '21600'];
        }

        $token = $context['token'] ?? '';
        if (! self::validOpaqueValue($token, 4096)) {
            throw new LogicException('Invalid AWS metadata token');
        }

        return ['X-aws-ec2-metadata-token' => $token];
    }

    /** @param array<string,string> $parameters */
    private function roleName(array $parameters): string
    {
        $role = $parameters['role'] ?? '';
        if (preg_match('/\A[A-Za-z0-9_+=,.@-]{1,64}\z/D', $role) !== 1) {
            throw new LogicException('Invalid AWS metadata role');
        }

        return $role;
    }

    private static function validOpaqueValue(string $value, int $maxLength): bool
    {
        return $value !== ''
            && strlen($value) <= $maxLength
            && preg_match('/[\x00-\x20\x7f]/', $value) !== 1;
    }
}
