<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

class DeployResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $remoteCertId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
    ) {}

    public static function ok(?string $remoteCertId = null): self
    {
        return new self(true, $remoteCertId);
    }

    public static function fail(string $errorCode, string $message): self
    {
        return new self(false, null, $errorCode, $message);
    }
}
