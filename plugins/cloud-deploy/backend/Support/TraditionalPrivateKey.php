<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Support;

use phpseclib3\Crypt\EC\PrivateKey as EcPrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA\PrivateKey as RsaPrivateKey;
use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Throwable;

final class TraditionalPrivateKey
{
    private const INVALID_MESSAGE = '证书私钥格式无效，无法部署';

    /**
     * 将系统私钥规范化为与 Certimate ACME 签发链路一致的传统 PEM：
     * RSA 使用 PKCS#1，EC 使用 SEC1。只转换交付副本，不修改证书库原值。
     */
    public static function convert(string $privateKey): string
    {
        try {
            $key = PublicKeyLoader::loadPrivateKey(trim($privateKey));

            $pem = match (true) {
                $key instanceof RsaPrivateKey => $key->toString('PKCS1'),
                $key instanceof EcPrivateKey => $key->toString('PKCS1'),
                default => throw new DeployBusinessException(self::INVALID_MESSAGE),
            };

            return rtrim($pem)."\n";
        } catch (DeployBusinessException $e) {
            throw $e;
        } catch (Throwable) {
            // 不附带底层解析异常，避免错误链意外包含私钥内容。
            throw new DeployBusinessException(self::INVALID_MESSAGE);
        }
    }
}
