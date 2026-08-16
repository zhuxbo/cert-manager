<?php

namespace Plugins\CloudDeploy\Deployers\Axisnow;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

class AxisnowErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        if ($e instanceof AxisnowApiException) {
            return CredentialScrubber::scrub('['.$e->getErrorCode().'] '.$e->getErrorMessage());
        }

        return 'AxisNow 调用失败: '.class_basename($e);
    }
}
