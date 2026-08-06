<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Azure\AzureApiException;
use Plugins\CloudDeploy\Deployers\Azure\AzureCloudEnv;
use Plugins\CloudDeploy\Deployers\Azure\AzureKeyVaultClient;
use Plugins\CloudDeploy\Deployers\Azure\AzureKeyVaultDeployer;
use Plugins\CloudDeploy\Deployers\Azure\AzureKeyVaultUploader;
use Plugins\CloudDeploy\Deployers\Azure\AzureOAuth2;
use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Tests\TestCase;

uses(TestCase::class);

// 合法 vault 名派生公网 host：resolver 对任意 host 返回公网 IP 放行，私网注入由策略拦截
beforeEach(function () {
    app()->instance(OutboundDestinationPolicy::class, new OutboundDestinationPolicy(
        resolver: static fn (string $host): array => $host === '' ? [] : ['93.184.216.34'],
    ));
});

/** 测试子类：override makeClient（oauth / api kind）。 */
function azureDeployerWith(callable $clientFactory): AzureKeyVaultDeployer
{
    return new class($clientFactory) extends AzureKeyVaultDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $token = '', string $vaultBaseUrl = ''): object
        {
            return ($this->factory)($kind, $credentials, $token, $vaultBaseUrl);
        }
    };
}

/** 生成自签证书 + 私钥（PEM），供 PKCS12 转换。返回 [certPem, keyPem]。 */
function azureSelfSignedCert(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'azure-test.example.com'], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $certPem);
    openssl_pkey_export($key, $keyPem);

    return [$certPem, $keyPem];
}

function azureCreds(): array
{
    return ['tenant_id' => 'tid', 'client_id' => 'cid', 'client_secret' => 'csecret'];
}

test('Azure Key Vault 为证书服务型 + 元信息', function () {
    $deployer = new AzureKeyVaultDeployer;
    expect($deployer->provider())->toBe('azure');
    expect($deployer->product())->toBe('keyvault');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['vault_name' => 'myvault']))->toBeInstanceOf(AzureKeyVaultUploader::class);
    expect($deployer->certUploader(['vault_name' => 'myvault'])->storeKind())->toBe('azure_keyvault:myvault');
});

test('cloud env 解析：公有云 / 中国 / 美政府', function () {
    expect(AzureCloudEnv::resolve('')['login'])->toBe('https://login.microsoftonline.com');
    expect(AzureCloudEnv::resolve('')['vaultScope'])->toBe('https://vault.azure.net/.default');
    expect(AzureCloudEnv::resolve('China')['login'])->toBe('https://login.chinacloudapi.cn');
    expect(AzureCloudEnv::resolve('China')['vaultDnsSuffix'])->toBe('vault.azure.cn');
    expect(AzureCloudEnv::resolve('USGovernment')['vaultDnsSuffix'])->toBe('vault.usgovcloudapi.net');
});

test('OAuth2 client_credentials KAT：端点 + form_params + scope 正确', function () {
    $captured = null;
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturnUsing(function ($method, $uri, $opts) use (&$captured) {
        $captured = [$method, $uri, $opts['form_params']];

        return new Response(200, [], (string) json_encode(['access_token' => 'AAD.TOKEN', 'token_type' => 'Bearer']));
    });

    $oauth = new AzureOAuth2($http);
    $token = $oauth->fetchAccessToken('my-tenant', 'my-client', 'my-secret', '');
    expect($token)->toBe('AAD.TOKEN');

    [$method, $uri, $form] = $captured;
    expect($method)->toBe('POST');
    expect($uri)->toBe('https://login.microsoftonline.com/my-tenant/oauth2/v2.0/token');
    expect($form['grant_type'])->toBe('client_credentials');
    expect($form['client_id'])->toBe('my-client');
    expect($form['client_secret'])->toBe('my-secret');
    expect($form['scope'])->toBe('https://vault.azure.net/.default');
});

test('OAuth2 中国云走 chinacloudapi 端点 + vault.azure.cn scope', function () {
    $captured = null;
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturnUsing(function ($method, $uri, $opts) use (&$captured) {
        $captured = [$uri, $opts['form_params']['scope']];

        return new Response(200, [], (string) json_encode(['access_token' => 'T']));
    });

    (new AzureOAuth2($http))->fetchAccessToken('t', 'c', 's', 'china');
    [$uri, $scope] = $captured;
    expect($uri)->toContain('login.chinacloudapi.cn');
    expect($scope)->toBe('https://vault.azure.cn/.default');
});

test('OAuth2 token 端点错误归一为 AzureApiException（脱敏不含 secret）', function () {
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->andReturn(
        new Response(401, [], (string) json_encode(['error' => 'invalid_client', 'error_description' => "AADSTS7000215: Invalid client secret\r\nTrace ID: x"]))
    );

    try {
        (new AzureOAuth2($http))->fetchAccessToken('t', 'c', 'my-secret-leak', '');
        expect(false)->toBeTrue('应抛异常');
    } catch (AzureApiException $e) {
        expect($e->getMessage())->toContain('invalid_client')->toContain('AADSTS7000215');
        expect($e->getMessage())->not->toContain('Trace ID'); // 截断到首行
        expect($e->getMessage())->not->toContain('my-secret-leak');
    }
});

test('uploader.upload 走 OAuth2 + importCertificate（PKCS12 可被空口令解回原证书）返回 kid', function () {
    [$certPem, $keyPem] = azureSelfSignedCert();

    $oauth = Mockery::mock(AzureOAuth2::class);
    $oauth->shouldReceive('fetchAccessToken')->once()->andReturn('AAD.TOKEN');

    $captured = null;
    $vaultUrlSeen = null;
    $client = Mockery::mock(AzureKeyVaultClient::class);
    $client->shouldReceive('importCertificate')->once()->andReturnUsing(function (string $name, string $pkcs12Base64, array $tags) use (&$captured) {
        $captured = [$name, $pkcs12Base64, $tags];

        return 'https://myvault.vault.azure.net/certificates/'.$name.'/abc123';
    });

    $deployer = azureDeployerWith(function (string $kind, array $cred, string $token, string $vaultBaseUrl) use ($oauth, $client, &$vaultUrlSeen) {
        if ($kind === 'oauth') {
            return $oauth;
        }
        $vaultUrlSeen = $vaultBaseUrl;

        return $client;
    });

    $kid = $deployer->certUploader(['vault_name' => 'myvault'])
        ->upload($certPem, $keyPem, '', azureCreds());

    [$name, $pkcs12Base64, $tags] = $captured;
    expect($name)->toStartWith('clouddeploy-');
    expect($vaultUrlSeen)->toBe('https://myvault.vault.azure.net');
    expect($tags)->toHaveKey('clouddeploy/cert-cn');
    expect($tags['clouddeploy/cert-cn'])->toBe('azure-test.example.com');
    expect($kid)->toContain('/certificates/');

    // PKCS12 round-trip：用空口令解回，确认含证书 + 私钥（证明 PEM→PFX 转换正确）
    $certs = [];
    $ok = openssl_pkcs12_read(base64_decode($pkcs12Base64), $certs, '');
    expect($ok)->toBeTrue();
    expect($certs)->toHaveKey('cert')->toHaveKey('pkey');
    expect(openssl_x509_parse($certs['cert'])['subject']['CN'])->toBe('azure-test.example.com');
});

test('bind 为 no-op：导入已由 RemoteCertStore 完成，不抛异常', function () {
    $deployer = azureDeployerWith(fn () => new stdClass);
    $deployer->bind('https://v.vault.azure.net/certificates/x/1', azureCreds(), []);
    expect($deployer->touchedConfigKeys())->toBe([]);
});

test('upload 遇 AzureApiException 脱敏重抛（无 secret/token、不挂 previous）', function () {
    [$certPem, $keyPem] = azureSelfSignedCert();
    $oauth = Mockery::mock(AzureOAuth2::class);
    $oauth->shouldReceive('fetchAccessToken')->andReturn('AAD.SECRET-TOKEN');
    $client = Mockery::mock(AzureKeyVaultClient::class);
    $client->shouldReceive('importCertificate')->andThrow(new AzureApiException('Forbidden', 'Caller is not authorized'));

    $deployer = azureDeployerWith(fn (string $kind) => $kind === 'oauth' ? $oauth : $client);
    try {
        $deployer->certUploader(['vault_name' => 'myvault'])->upload($certPem, $keyPem, '', azureCreds());
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Forbidden')->toContain('not authorized');
        expect($e->getMessage())->not->toContain('AAD.SECRET-TOKEN');
        expect($e->getPrevious())->toBeNull();
    }
});

test('vault_name 含 URL 分隔符时在调用客户端前被拒绝', function (string $vaultName) {
    [$certPem, $keyPem] = azureSelfSignedCert();
    $deployer = azureDeployerWith(fn () => new stdClass);

    expect(fn () => $deployer->certUploader(['vault_name' => $vaultName])->upload($certPem, $keyPem, '', azureCreds()))
        ->toThrow(OutboundDestinationException::class);
})->with([
    'private host rewrite' => '127.0.0.1:6443/foo',
    'public host rewrite' => 'public.example:443/path',
]);
