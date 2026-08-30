<?php

declare(strict_types=1);

namespace App\Services\Delegation\Sdk\Aliyun;

final class AliyunRpcSigner
{
    /** @param array<string, scalar> $parameters */
    public static function sign(array $parameters, string $accessKeySecret): string
    {
        ksort($parameters, SORT_STRING);

        $pairs = [];
        foreach ($parameters as $key => $value) {
            $pairs[] = self::encode($key).'='.self::encode((string) $value);
        }

        $stringToSign = 'GET&%2F&'.self::encode(implode('&', $pairs));

        return base64_encode(hash_hmac(
            'sha1',
            $stringToSign,
            $accessKeySecret.'&',
            true,
        ));
    }

    private static function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
