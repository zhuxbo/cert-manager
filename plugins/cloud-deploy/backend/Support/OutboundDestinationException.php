<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Support;

use RuntimeException;

class OutboundDestinationException extends RuntimeException
{
    public function __construct(private readonly string $reasonCode)
    {
        parent::__construct('部署目标地址不符合出站安全策略');
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
