<?php

namespace Plugins\CloudDeploy\Deployers\Yandexcloud;

use Plugins\CloudDeploy\Deployers\Contracts\CredentialScrubber;
use Throwable;

class YandexcloudErrorSanitizer
{
    public static function sanitize(Throwable $e): string
    {
        if ($e instanceof YandexcloudApiException) {
            return CredentialScrubber::scrub($e->getMessage());
        }

        return 'Yandex Cloud 调用失败: '.class_basename($e);
    }
}
