<?php

namespace Plugins\CloudDeploy\Deployers\Huaweiibmc;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

class HuaweiibmcErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        if ($e instanceof HuaweiibmcApiException) {
            return CredentialScrubber::scrub('['.$e->getErrorCode().'] '.$e->getErrorMessage());
        }

        return 'Huawei iBMC 调用失败: '.class_basename($e);
    }
}
