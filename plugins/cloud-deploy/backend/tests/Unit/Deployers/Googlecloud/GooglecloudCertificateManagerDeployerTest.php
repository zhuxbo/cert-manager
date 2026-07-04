<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Googlecloud\GcpCertManagerUploader;
use Plugins\CloudDeploy\Deployers\Googlecloud\GooglecloudApiException;
use Plugins\CloudDeploy\Deployers\Googlecloud\GooglecloudCertificateManagerDeployer;
use Plugins\CloudDeploy\Deployers\Googlecloud\GooglecloudClient;
use Plugins\CloudDeploy\Deployers\Googlecloud\GoogleOAuth2;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（oauth / api kind）。 */
function googlecloudDeployerWith(callable $clientFactory): GooglecloudCertificateManagerDeployer
{
    return new class($clientFactory) extends GooglecloudCertificateManagerDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $token = ''): object
        {
            return ($this->factory)($kind, $credentials, $token);
        }
    };
}

/** 生成一对 RSA 密钥，返回 [privatePem, publicPem]，用于 JWT 签名 KAT。 */
function googleTestRsaKeypair(): array
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $privPem);
    $pub = openssl_pkey_get_details($res)['key'];

    return [$privPem, $pub];
}

function googleSaJson(string $privatePem, string $project = 'my-proj'): string
{
    return (string) json_encode([
        'type' => 'service_account',
        'project_id' => $project,
        'client_email' => 'sa@my-proj.iam.gserviceaccount.com',
        'private_key' => $privatePem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]);
}

test('Google Cloud 证书管理器为证书服务型 + 元信息', function () {
    $deployer = new GooglecloudCertificateManagerDeployer;
    expect($deployer->provider())->toBe('googlecloud');
    expect($deployer->product())->toBe('certificatemanager');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->toBeInstanceOf(GcpCertManagerUploader::class);
    expect($deployer->certUploader()->storeKind())->toBe('gcp_certmanager:global');
    expect($deployer->certUploader(['location' => 'us-central1'])->storeKind())->toBe('gcp_certmanager:us-central1');
});

test('OAuth2 自签 JWT (RS256) KAT：header/claims 正确且签名可被 SA 公钥验签', function () {
    [$priv, $pub] = googleTestRsaKeypair();

    $capturedAssertion = null;
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturnUsing(function ($method, $uri, $opts) use (&$capturedAssertion) {
        expect($method)->toBe('POST');
        expect($uri)->toBe('https://oauth2.googleapis.com/token');
        expect($opts['form_params']['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');
        $capturedAssertion = $opts['form_params']['assertion'];

        return new Response(200, [], (string) json_encode(['access_token' => 'ya29.TOKEN', 'expires_in' => 3600]));
    });

    $oauth = new GoogleOAuth2($http);
    $token = $oauth->fetchAccessToken(googleSaJson($priv));
    expect($token)->toBe('ya29.TOKEN');

    // 拆 JWT 三段，验 header + claims
    [$h64, $c64, $s64] = explode('.', $capturedAssertion);
    $b64urlDecode = fn (string $s) => base64_decode(strtr($s, '-_', '+/'));
    $header = json_decode($b64urlDecode($h64), true);
    $claims = json_decode($b64urlDecode($c64), true);
    expect($header)->toBe(['alg' => 'RS256', 'typ' => 'JWT']);
    expect($claims['iss'])->toBe('sa@my-proj.iam.gserviceaccount.com');
    expect($claims['aud'])->toBe('https://oauth2.googleapis.com/token');
    expect($claims['scope'])->toBe(GoogleOAuth2::SCOPE_CLOUD_PLATFORM);
    expect($claims['exp'] - $claims['iat'])->toBe(3600);

    // 用 SA 公钥验签名（确认 RS256 签名对 "h.c" 有效）
    $signingInput = "$h64.$c64";
    $sig = $b64urlDecode($s64);
    expect(openssl_verify($signingInput, $sig, $pub, OPENSSL_ALGO_SHA256))->toBe(1);
});

test('OAuth2 token 端点错误归一为 GooglecloudApiException（脱敏不含私钥）', function () {
    [$priv] = googleTestRsaKeypair();
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->andReturn(
        new Response(400, [], (string) json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT']))
    );

    $oauth = new GoogleOAuth2($http);
    try {
        $oauth->fetchAccessToken(googleSaJson($priv));
        expect(false)->toBeTrue('应抛异常');
    } catch (GooglecloudApiException $e) {
        expect($e->getMessage())->toContain('invalid_grant')->toContain('Invalid JWT');
        expect($e->getMessage())->not->toContain('PRIVATE KEY');
    }
});

test('非法 service account JSON 抛 GooglecloudApiException', function () {
    $oauth = new GoogleOAuth2(Mockery::mock(ClientInterface::class));
    expect(fn () => $oauth->fetchAccessToken('not-json'))
        ->toThrow(GooglecloudApiException::class, '不是合法 JSON');
    expect(fn () => $oauth->fetchAccessToken((string) json_encode(['type' => 'x'])))
        ->toThrow(GooglecloudApiException::class, '缺少 client_email');
});

test('uploader.upload 走 OAuth2 + createCertificate（完整链 + 私钥 + selfManaged）返回资源名', function () {
    $oauth = Mockery::mock(GoogleOAuth2::class);
    $oauth->shouldReceive('fetchAccessToken')->once()->andReturn('ya29.TOKEN');

    $captured = null;
    $client = Mockery::mock(GooglecloudClient::class);
    $client->shouldReceive('createCertificate')->once()->andReturnUsing(function (string $project, string $location, string $certId, array $body) use (&$captured) {
        $captured = [$project, $location, $certId, $body];

        return "projects/$project/locations/$location/certificates/$certId";
    });

    $deployer = googlecloudDeployerWith(fn (string $kind) => $kind === 'oauth' ? $oauth : $client);
    $resourceName = $deployer->certUploader(['location' => 'global'])
        ->upload('LEAFPEM', 'KEYPEM', 'CHAINPEM', ['credentials_json' => googleSaJson('PRIV')]);

    [$project, $location, $certId, $body] = $captured;
    expect($project)->toBe('my-proj'); // 从 SA JSON 的 project_id 派生
    expect($location)->toBe('global');
    expect($certId)->toStartWith('clouddeploy-');
    expect($body['selfManaged']['pemCertificate'])->toContain('LEAFPEM')->toContain('CHAINPEM');
    expect($body['selfManaged']['pemPrivateKey'])->toBe('KEYPEM');
    expect($resourceName)->toBe("projects/my-proj/locations/global/certificates/$certId");
});

test('config.project 覆盖 SA JSON 的 project_id', function () {
    $oauth = Mockery::mock(GoogleOAuth2::class);
    $oauth->shouldReceive('fetchAccessToken')->andReturn('ya29.TOKEN');
    $captured = null;
    $client = Mockery::mock(GooglecloudClient::class);
    $client->shouldReceive('createCertificate')->andReturnUsing(function (string $project) use (&$captured) {
        $captured = $project;

        return "projects/$project/locations/global/certificates/x";
    });

    $deployer = googlecloudDeployerWith(fn (string $kind) => $kind === 'oauth' ? $oauth : $client);
    $deployer->certUploader(['project' => 'override-proj'])
        ->upload('C', 'K', 'CH', ['credentials_json' => googleSaJson('PRIV', 'sa-json-proj')]);

    expect($captured)->toBe('override-proj');
});

test('bind 为 no-op：上传已由 RemoteCertStore 完成，不抛异常', function () {
    $deployer = googlecloudDeployerWith(fn () => new stdClass);
    $deployer->bind('projects/p/locations/global/certificates/x', ['credentials_json' => '{}'], []);
    expect($deployer->touchedConfigKeys())->toBe([]);
});

test('缺 credentials_json 抛明确异常', function () {
    $deployer = googlecloudDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', []))
        ->toThrow(RuntimeException::class, '缺少服务账号密钥');
});

test('upload 遇 GooglecloudApiException 脱敏重抛（无 token/私钥、不挂 previous）', function () {
    $oauth = Mockery::mock(GoogleOAuth2::class);
    $oauth->shouldReceive('fetchAccessToken')->andReturn('ya29.SECRET-TOKEN');
    $client = Mockery::mock(GooglecloudClient::class);
    $client->shouldReceive('createCertificate')->andThrow(new GooglecloudApiException('PERMISSION_DENIED', 'caller lacks permission'));

    $deployer = googlecloudDeployerWith(fn (string $kind) => $kind === 'oauth' ? $oauth : $client);
    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['credentials_json' => googleSaJson('PRIV')]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('PERMISSION_DENIED')->toContain('caller lacks permission');
        expect($e->getMessage())->not->toContain('ya29.SECRET-TOKEN');
        expect($e->getPrevious())->toBeNull();
    }
});
