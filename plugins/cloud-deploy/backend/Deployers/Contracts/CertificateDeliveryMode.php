<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/** 证书进入 deployer 的交付方式；RemoteStore 不会把私钥交给 bind。 */
enum CertificateDeliveryMode: string
{
    case RemoteStore = 'remote_store';
    case Inline = 'inline';
}
