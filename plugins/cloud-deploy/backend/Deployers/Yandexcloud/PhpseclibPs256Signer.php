<?php

namespace Plugins\CloudDeploy\Deployers\Yandexcloud;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey as RsaPrivateKey;
use Throwable;

/** phpseclib 负责 RSA-PSS 运算，本类只组装标准紧凑 JWS。 */
class PhpseclibPs256Signer implements Ps256SignerInterface
{
    public function sign(array $header, array $claims, string $privateKey): string
    {
        if (! class_exists(RSA::class)) {
            throw new YandexcloudApiException('DependencyMissing');
        }

        try {
            $segments = [
                $this->base64UrlEncode((string) json_encode($header, JSON_THROW_ON_ERROR)),
                $this->base64UrlEncode((string) json_encode($claims, JSON_THROW_ON_ERROR)),
            ];
            $input = implode('.', $segments);
            /** @var RsaPrivateKey $key */
            $key = RSA::loadPrivateKey($privateKey);
            $key = $key
                ->withPadding(RSA::SIGNATURE_PSS)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->withSaltLength(32);
            $segments[] = $this->base64UrlEncode($key->sign($input));

            return implode('.', $segments);
        } catch (Throwable) {
            throw new YandexcloudApiException('InvalidCredential');
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
