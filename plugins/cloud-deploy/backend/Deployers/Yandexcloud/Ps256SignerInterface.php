<?php

namespace Plugins\CloudDeploy\Deployers\Yandexcloud;

interface Ps256SignerInterface
{
    /**
     * @param  array<string,mixed>  $header
     * @param  array<string,mixed>  $claims
     */
    public function sign(array $header, array $claims, string $privateKey): string;
}
