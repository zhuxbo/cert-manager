<?php

namespace Plugins\CloudDeploy\Deployers\Proxmoxbs;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

class ProxmoxbsErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        if ($e instanceof ProxmoxbsApiException) {
            return CredentialScrubber::scrub('['.$e->getErrorCode().'] '.$e->getErrorMessage());
        }

        return 'Proxmox BS 调用失败: '.class_basename($e);
    }
}
