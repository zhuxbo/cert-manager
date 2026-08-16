<?php

use phpseclib3\Crypt\RSA;
use Plugins\CloudDeploy\Deployers\Yandexcloud\PhpseclibPs256Signer;
use Tests\TestCase;

uses(TestCase::class);

test('phpseclib 适配器生成标准 PS256 JWS，可由独立公钥配置验签', function () {
    $privateKey = RSA::createKey(2048);
    $signer = new PhpseclibPs256Signer;
    $jwt = $signer->sign(
        ['typ' => 'JWT', 'alg' => 'PS256', 'kid' => 'key-id'],
        ['iss' => 'service-account-id', 'aud' => 'audience', 'iat' => 100, 'exp' => 3700],
        $privateKey->toString('PKCS8'),
    );

    [$header64, $claims64, $signature64] = explode('.', $jwt);
    $decode = static fn (string $value): string => (string) base64_decode(
        strtr($value.str_repeat('=', (4 - strlen($value) % 4) % 4), '-_', '+/'),
        true,
    );
    expect(json_decode($decode($header64), true))->toBe(['typ' => 'JWT', 'alg' => 'PS256', 'kid' => 'key-id']);
    expect(json_decode($decode($claims64), true))->toMatchArray([
        'iss' => 'service-account-id',
        'aud' => 'audience',
    ]);

    $verifier = $privateKey->getPublicKey()
        ->withPadding(RSA::SIGNATURE_PSS)
        ->withHash('sha256')
        ->withMGFHash('sha256')
        ->withSaltLength(32);
    expect($verifier->verify("$header64.$claims64", $decode($signature64)))->toBeTrue();

    $wrongSaltVerifier = $privateKey->getPublicKey()
        ->withPadding(RSA::SIGNATURE_PSS)
        ->withHash('sha256')
        ->withMGFHash('sha256')
        ->withSaltLength(20);
    expect($wrongSaltVerifier->verify("$header64.$claims64", $decode($signature64)))->toBeFalse();
});
