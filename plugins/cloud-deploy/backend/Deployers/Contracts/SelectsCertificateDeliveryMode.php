<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * 仅当同一 endpoint 的云厂商 API 会依 target config 改变证书交付方式时实现。
 * 未 opt-in 的 deployer 继续以 usesRemoteCertStore() 为唯一行为，避免改变既有端点。
 */
interface SelectsCertificateDeliveryMode
{
    /** @param  array<string,mixed>  $config */
    public function certificateDeliveryMode(array $config): CertificateDeliveryMode;
}
