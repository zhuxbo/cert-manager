<?php

namespace Plugins\CloudDeploy\Deployers\Yandexcloud;

use Closure;
use GuzzleHttp\ClientInterface;
use Throwable;

class YandexcloudIamAuthenticator
{
    public const AUDIENCE = 'https://iam.api.cloud.yandex.net/iam/v1/tokens';

    private readonly Closure $clock;

    public function __construct(
        private readonly Ps256SignerInterface $signer,
        private readonly ClientInterface $http,
        ?callable $clock = null,
    ) {
        $this->clock = Closure::fromCallable($clock ?? time(...));
    }

    public function fetchIamToken(string $serviceAccountKey): string
    {
        $key = json_decode($serviceAccountKey, true);
        if (! is_array($key)) {
            throw new YandexcloudApiException('InvalidCredential', '服务账号密钥不是合法 JSON');
        }

        $keyId = trim((string) ($key['id'] ?? ''));
        $serviceAccountId = trim((string) ($key['service_account_id'] ?? ''));
        $privateKey = (string) ($key['private_key'] ?? '');
        if ($keyId === '' || $serviceAccountId === '' || $privateKey === '') {
            throw new YandexcloudApiException('InvalidCredential', '服务账号密钥缺少 id / service_account_id / private_key');
        }

        $privateKey = $this->stripMetadataLine($privateKey);
        $now = (int) ($this->clock)();
        $jwt = $this->signer->sign(
            ['typ' => 'JWT', 'alg' => 'PS256', 'kid' => $keyId],
            [
                'iss' => $serviceAccountId,
                'aud' => self::AUDIENCE,
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + 3600,
            ],
            $privateKey,
        );

        try {
            $response = $this->http->request('POST', 'iam/v1/tokens', [
                'json' => ['jwt' => $jwt],
                'http_errors' => false,
            ]);
        } catch (Throwable) {
            throw new YandexcloudApiException('HttpError');
        }

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : [];
        if ($status < 200 || $status >= 300) {
            throw YandexcloudApiException::fromStatus($body['code'] ?? $status);
        }

        $token = (string) ($body['iamToken'] ?? '');
        if ($token === '') {
            throw new YandexcloudApiException('InvalidResponse');
        }

        return $token;
    }

    private function stripMetadataLine(string $privateKey): string
    {
        if (str_starts_with($privateKey, 'PLEASE DO NOT REMOVE THIS LINE!')) {
            $lineEnd = strpos($privateKey, "\n");

            return $lineEnd === false ? '' : substr($privateKey, $lineEnd + 1);
        }

        return $privateKey;
    }
}
