<?php

declare(strict_types=1);

namespace App\Services\Delegation\Sdk\TencentCloud;

final class TencentCloudTc3Signer
{
    public const string CONTENT_TYPE = 'application/json; charset=utf-8';

    public static function authorization(
        string $secretId,
        string $secretKey,
        string $host,
        string $service,
        int $timestamp,
        string $payload,
    ): string {
        $canonicalHeaders = 'content-type:'.self::CONTENT_TYPE."\n"
            .'host:'.$host."\n";
        $signedHeaders = 'content-type;host';
        $canonicalRequest = "POST\n/\n\n"
            .$canonicalHeaders."\n"
            .$signedHeaders."\n"
            .hash('sha256', $payload);

        $date = gmdate('Y-m-d', $timestamp);
        $credentialScope = $date.'/'.$service.'/tc3_request';
        $stringToSign = "TC3-HMAC-SHA256\n"
            .$timestamp."\n"
            .$credentialScope."\n"
            .hash('sha256', $canonicalRequest);

        $secretDate = hash_hmac('sha256', $date, 'TC3'.$secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        return 'TC3-HMAC-SHA256 Credential='.$secretId.'/'.$credentialScope
            .', SignedHeaders='.$signedHeaders
            .', Signature='.$signature;
    }
}
