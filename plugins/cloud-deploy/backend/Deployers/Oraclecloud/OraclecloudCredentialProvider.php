<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

interface OraclecloudCredentialProvider
{
    public function resolve(): OraclecloudAuthMaterial;
}
