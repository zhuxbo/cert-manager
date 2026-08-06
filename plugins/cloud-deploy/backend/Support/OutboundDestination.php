<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Support;

final readonly class OutboundDestination
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $scope,
        public array $addresses,
    ) {}
}
