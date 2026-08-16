<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Aws\AwsMetadataRoute;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OciRequestSigner;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OracleApiKeyCredentialProvider;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudApiException;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudAuthMaterial;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudCertificatesMgmtDeployer;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudClient;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OracleInstancePrincipalProvider;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OracleMetadataRoute;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OracleResourcePrincipalProvider;
use Plugins\CloudDeploy\Support\CloudMetadataHttpClient;
use Tests\TestCase;

uses(TestCase::class);

function oraclePrincipalJwt(int $expiration, string $marker = 'principal'): string
{
    $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return $encode(['alg' => 'RS256']).'.'.$encode(['exp' => $expiration, 'sub' => $marker]).'.test-signature';
}

/** @return array{0:string,1:string} */
function oraclePrincipalKeypair(): array
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $privateKey);
    $details = openssl_pkey_get_details($resource);

    return [$privateKey, $details['key']];
}

/** @return array{0:string,1:string} */
function oracleInstanceCertificate(string $tenancy): array
{
    [$privateKey] = oraclePrincipalKeypair();
    $csr = openssl_csr_new([
        'commonName' => 'instance-principal-test',
        'organizationalUnitName' => 'opc-tenant:'.$tenancy,
    ], $privateKey, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $privateKey, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $certificatePem);

    return [$certificatePem, $privateKey];
}

/**
 * @param  list<Response>  $responses
 * @param  list<array<string,mixed>>  $history
 */
function oracleMetadataFixture(array $responses, array &$history): CloudMetadataHttpClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return CloudMetadataHttpClient::forOracle(new Client(['handler' => $stack]));
}

test('OCI metadata 仅走固定 opc v2 路由并强制 Bearer Oracle', function () {
    $history = [];
    $metadata = oracleMetadataFixture([
        new Response(200, [], 'ap-tokyo-1'),
        new Response(200, [], 'leaf'),
        new Response(200, [], 'intermediate'),
        new Response(200, [], 'private-key'),
    ], $history);

    expect($metadata->request(OracleMetadataRoute::Region))->toBe('ap-tokyo-1')
        ->and($metadata->request(OracleMetadataRoute::LeafCertificate))->toBe('leaf')
        ->and($metadata->request(OracleMetadataRoute::IntermediateCertificate))->toBe('intermediate')
        ->and($metadata->request(OracleMetadataRoute::PrivateKey))->toBe('private-key')
        ->and(array_map(static fn (array $transaction): string => (string) $transaction['request']->getUri(), $history))->toBe([
            'http://169.254.169.254/opc/v2/instance/region',
            'http://169.254.169.254/opc/v2/identity/cert.pem',
            'http://169.254.169.254/opc/v2/identity/intermediate.pem',
            'http://169.254.169.254/opc/v2/identity/key.pem',
        ]);

    foreach ($history as $transaction) {
        expect($transaction['request']->getMethod())->toBe('GET')
            ->and($transaction['request']->getHeaderLine('Authorization'))->toBe('Bearer Oracle')
            ->and($transaction['options']['allow_redirects'])->toBeFalse()
            ->and($transaction['options']['proxy'] ?? null)->toBeNull();
    }
});

test('OCI metadata factory 与 AWS route enum 双向隔离', function () {
    $oracleHistory = [];
    $awsHistory = [];
    $oracle = oracleMetadataFixture([], $oracleHistory);
    $aws = CloudMetadataHttpClient::forAws(new Client([
        'handler' => HandlerStack::create(new MockHandler([])),
    ]));

    expect(fn () => $oracle->request(AwsMetadataRoute::Token))->toThrow(LogicException::class)
        ->and(fn () => $aws->request(OracleMetadataRoute::Region))->toThrow(LogicException::class)
        ->and($oracleHistory)->toBe([])
        ->and($awsHistory)->toBe([]);
});

test('instance principal 向固定 region federation host 换取安全令牌并以 ST token 签名', function () {
    $now = 1893456000;
    [$leafCertificate, $leafPrivateKey] = oracleInstanceCertificate('ocid1.tenancy.oc1..instance');
    [$intermediateCertificate] = oracleInstanceCertificate('ocid1.tenancy.oc1..instance');
    [$sessionPrivateKey, $sessionPublicKey] = oraclePrincipalKeypair();
    $token = oraclePrincipalJwt($now + 3600, 'INSTANCE-TOKEN-MARKER');

    $metadataHistory = [];
    $metadata = oracleMetadataFixture([
        new Response(200, [], 'ap-tokyo-1'),
        new Response(200, [], $leafCertificate),
        new Response(200, [], $intermediateCertificate),
        new Response(200, [], $leafPrivateKey),
    ], $metadataHistory);
    $federationHistory = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['token' => $token], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($federationHistory));

    $provider = new OracleInstancePrincipalProvider(
        $metadata,
        new Client(['handler' => $stack]),
        static fn (): int => $now,
        static fn (): array => [$sessionPrivateKey, $sessionPublicKey],
        static fn (string $host): null => null,
    );
    $material = $provider->resolve();
    $serviceDate = 'Mon, 01 Jan 2030 00:00:00 GMT';
    $serviceHeaders = OciRequestSigner::fromAuthMaterial($material)
        ->sign('GET', 'certificatesmanagement.ap-tokyo-1.oci.oraclecloud.com', '/20210224/certificates', '', $serviceDate);
    $authorization = $serviceHeaders['Authorization'];
    preg_match('/signature="([^"]+)"/', $authorization, $serviceSignature);
    $serviceSigningString = implode("\n", [
        '(request-target): get /20210224/certificates',
        'date: '.$serviceDate,
        'host: certificatesmanagement.ap-tokyo-1.oci.oraclecloud.com',
    ]);

    $federationRequest = $federationHistory[0]['request'];
    $federationBody = (string) $federationRequest->getBody();
    $federationPayload = json_decode($federationBody, true);
    preg_match('/signature="([^"]+)"/', $federationRequest->getHeaderLine('Authorization'), $federationSignature);
    $federationSigningString = implode("\n", [
        '(request-target): post /v1/x509',
        'date: '.$federationRequest->getHeaderLine('date'),
        'host: auth.ap-tokyo-1.oraclecloud.com',
        'x-content-sha256: '.base64_encode(hash('sha256', $federationBody, true)),
        'content-type: application/json',
        'content-length: '.strlen($federationBody),
    ]);

    expect($material->region())->toBe('ap-tokyo-1')
        ->and($material->keyId())->toBe('ST$'.$token)
        ->and($authorization)->toContain('keyId="ST$'.$token.'"')
        ->and(openssl_verify($serviceSigningString, base64_decode($serviceSignature[1]), $sessionPublicKey, OPENSSL_ALGO_SHA256))->toBe(1)
        ->and($federationHistory)->toHaveCount(1)
        ->and((string) $federationRequest->getUri())->toBe('https://auth.ap-tokyo-1.oraclecloud.com/v1/x509')
        ->and($federationRequest->getHeaderLine('Authorization'))->toContain('/fed-x509-sha256/')
        ->and(openssl_verify($federationSigningString, base64_decode($federationSignature[1]), openssl_pkey_get_public($leafCertificate), OPENSSL_ALGO_SHA256))->toBe(1)
        ->and($federationPayload)->toMatchArray([
            'fingerprintAlgorithm' => 'SHA256',
        ])
        ->and($federationPayload['certificate'])->not->toContain('CERTIFICATE')->not->toContain("\n")
        ->and($federationPayload['publicKey'])->not->toContain('PUBLIC KEY')->not->toContain("\n")
        ->and($federationPayload['intermediateCertificates'][0])->not->toContain('CERTIFICATE')->not->toContain("\n");
});

test('instance principal 在令牌到期前五分钟刷新且畸形响应失败关闭不泄密', function () {
    $now = 1893456000;
    [$leafCertificate, $leafPrivateKey] = oracleInstanceCertificate('ocid1.tenancy.oc1..refresh');
    [$intermediateCertificate] = oracleInstanceCertificate('ocid1.tenancy.oc1..refresh');
    [$sessionPrivateKey, $sessionPublicKey] = oraclePrincipalKeypair();
    $metadataHistory = [];
    $metadata = oracleMetadataFixture([
        new Response(200, [], 'us-ashburn-1'),
        new Response(200, [], $leafCertificate),
        new Response(200, [], $intermediateCertificate),
        new Response(200, [], $leafPrivateKey),
        new Response(200, [], 'us-ashburn-1'),
        new Response(200, [], $leafCertificate),
        new Response(200, [], $intermediateCertificate),
        new Response(200, [], $leafPrivateKey),
    ], $metadataHistory);
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['token' => oraclePrincipalJwt($now + 600, 'first')], JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"token":"UPSTREAM-SECRET-NOT-A-JWT"}'),
    ]));
    $provider = new OracleInstancePrincipalProvider(
        $metadata,
        new Client(['handler' => $stack]),
        static function () use (&$now): int {
            return $now;
        },
        static fn (): array => [$sessionPrivateKey, $sessionPublicKey],
        static fn (string $host): null => null,
    );

    expect($provider->resolve()->expiresAt())->toBe($now + 600)
        ->and($provider->resolve()->expiresAt())->toBe($now + 600)
        ->and($metadataHistory)->toHaveCount(4);

    $now += 301;
    try {
        $provider->resolve();
        test()->fail('畸形 federation token 必须失败关闭');
    } catch (Throwable $e) {
        expect($e->getMessage())->toBe('Oracle instance principal 获取安全令牌失败')
            ->and($e->getMessage())->not->toContain('UPSTREAM-SECRET')
            ->and($e->getPrevious())->toBeNull();
    }
});

test('resource principal 2.2 支持全内联并在每次 resolve 重读全路径材料', function () {
    $now = 1893456000;
    [$firstKey] = oraclePrincipalKeypair();
    [$secondKey] = oraclePrincipalKeypair();
    $firstToken = oraclePrincipalJwt($now + 3600, 'first');
    $secondToken = oraclePrincipalJwt($now + 7200, 'second');
    $files = [
        '/runtime/region' => 'ap-tokyo-1',
        '/runtime/rpst' => $firstToken,
        '/runtime/key.pem' => $firstKey,
    ];
    $environment = [
        'OCI_RESOURCE_PRINCIPAL_VERSION' => '2.2',
        'OCI_RESOURCE_PRINCIPAL_REGION' => '/runtime/region',
        'OCI_RESOURCE_PRINCIPAL_RPST' => '/runtime/rpst',
        'OCI_RESOURCE_PRINCIPAL_PRIVATE_PEM' => '/runtime/key.pem',
    ];
    $provider = new OracleResourcePrincipalProvider(
        static fn (string $name): string|false => $environment[$name] ?? false,
        static function (string $path) use (&$files): string|false {
            return $files[$path] ?? false;
        },
        static fn (): int => $now,
    );

    expect($provider->resolve()->keyId())->toBe('ST$'.$firstToken);
    $files['/runtime/rpst'] = $secondToken;
    $files['/runtime/key.pem'] = $secondKey;
    expect($provider->resolve()->keyId())->toBe('ST$'.$secondToken);

    $inline = new OracleResourcePrincipalProvider(
        static fn (string $name): string|false => match ($name) {
            'OCI_RESOURCE_PRINCIPAL_VERSION' => '2.2',
            'OCI_RESOURCE_PRINCIPAL_REGION' => 'eu-frankfurt-1',
            'OCI_RESOURCE_PRINCIPAL_RPST' => $firstToken,
            'OCI_RESOURCE_PRINCIPAL_PRIVATE_PEM' => $firstKey,
            default => false,
        },
        null,
        static fn (): int => $now,
    );
    expect($inline->resolve()->region())->toBe('eu-frankfurt-1');
});

test('resource principal 拒绝 2.2 路径与内联混用且异常不泄露 RPST', function () {
    [$privateKey] = oraclePrincipalKeypair();
    $secret = oraclePrincipalJwt(1893459600, 'RPST-SECRET-MARKER');
    $provider = new OracleResourcePrincipalProvider(
        static fn (string $name): string|false => match ($name) {
            'OCI_RESOURCE_PRINCIPAL_VERSION' => '2.2',
            'OCI_RESOURCE_PRINCIPAL_REGION' => 'ap-tokyo-1',
            'OCI_RESOURCE_PRINCIPAL_RPST' => $secret,
            'OCI_RESOURCE_PRINCIPAL_PRIVATE_PEM' => '/runtime/key.pem',
            default => false,
        },
        static fn (string $path): string|false => $privateKey,
        static fn (): int => 1893456000,
    );

    try {
        $provider->resolve();
        test()->fail('resource principal 混用模式必须失败关闭');
    } catch (Throwable $e) {
        expect($e->getMessage())->toBe('Oracle resource principal 凭证无效')
            ->and($e->getMessage())->not->toContain('RPST-SECRET-MARKER')
            ->and($e->getPrevious())->toBeNull();
    }
});

test('Oracle deployer 明确选择 API key、instance principal 或 resource principal provider', function () {
    $deployer = new OraclecloudCertificatesMgmtDeployer;
    $makeClient = new ReflectionMethod($deployer, 'makeClient');

    expect($makeClient->invoke($deployer, 'provider', []))->toBeInstanceOf(OracleApiKeyCredentialProvider::class)
        ->and($makeClient->invoke($deployer, 'provider', ['auth_method' => 'instanceprincipal']))->toBeInstanceOf(OracleInstancePrincipalProvider::class)
        ->and($makeClient->invoke($deployer, 'provider', ['auth_method' => 'resourceprincipal']))->toBeInstanceOf(OracleResourcePrincipalProvider::class)
        ->and(fn () => $makeClient->invoke($deployer, 'provider', ['auth_method' => 'config-file']))
        ->toThrow(InvalidArgumentException::class, '不支持的 Oracle Cloud 认证方式');
});

test('Oracle API 错误即使反射 security token 或签名头也会先脱敏', function () {
    [$privateKey] = oraclePrincipalKeypair();
    $token = oraclePrincipalJwt(1893459600, 'REFLECTED-TOKEN-SECRET');
    $signer = OciRequestSigner::fromAuthMaterial(OraclecloudAuthMaterial::securityToken(
        $token,
        $privateKey,
        'ap-tokyo-1',
        '',
        1893459600,
    ));
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(401, [], json_encode([
            'code' => 'NotAuthenticated',
            'message' => 'reflected ST$'.$token.' and Signature version="1" secret-signature',
        ], JSON_THROW_ON_ERROR)),
    ]))]);
    $client = new OraclecloudClient($signer, 'ap-tokyo-1', $http);

    try {
        $client->createImportedCertificate('compartment', 'name', 'cert', '', 'key');
        test()->fail('OCI 401 必须抛结构化异常');
    } catch (OraclecloudApiException $e) {
        expect($e->getErrorMessage())->not->toContain($token)
            ->not->toContain('REFLECTED-TOKEN-SECRET')
            ->not->toContain('Signature version=');
    }
});
