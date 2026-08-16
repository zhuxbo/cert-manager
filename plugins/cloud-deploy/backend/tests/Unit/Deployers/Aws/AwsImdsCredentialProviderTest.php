<?php

use Aws\Acm\AcmClient;
use Aws\Amplify\AmplifyClient;
use Aws\ApiGatewayV2\ApiGatewayV2Client;
use Aws\AwsClient;
use Aws\CloudFront\CloudFrontClient;
use Aws\Credentials\CredentialsInterface;
use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\Iam\IamClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Aws\AwsAcmDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsAlbDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsAmplifyDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsApigatewayDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsClbDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsCloudFrontDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsIamDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsImdsCredentialProvider;
use Plugins\CloudDeploy\Deployers\Aws\AwsNlbDeployer;
use Plugins\CloudDeploy\Support\CloudMetadataHttpClient;
use Plugins\CloudDeploy\Support\CloudMetadataRoute;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  list<Response>  $responses
 * @param  list<array<string,mixed>>  $history
 */
function awsMetadataFixture(array $responses, array &$history): CloudMetadataHttpClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return CloudMetadataHttpClient::forAws(new Client(['handler' => $stack]));
}

function awsImdsSuccessJson(string $expiration): string
{
    return json_encode([
        'Code' => 'Success',
        'AccessKeyId' => 'AKIA-IMDS-TEMP',
        'SecretAccessKey' => 'IMDS-SECRET-NEVER-LOG',
        'Token' => 'IMDS-SESSION-NEVER-LOG',
        'Expiration' => $expiration,
    ], JSON_THROW_ON_ERROR);
}

test('IMDSv2 固定执行 PUT token、GET role、GET credentials 且禁代理重定向', function () {
    $history = [];
    $http = awsMetadataFixture([
        new Response(200, [], 'metadata-token-never-log'),
        new Response(200, [], "ssl-manager-role\n"),
        new Response(200, [], awsImdsSuccessJson('2030-01-01T01:00:00Z')),
    ], $history);
    $provider = new AwsImdsCredentialProvider($http, static fn (): int => 1893456000);

    $credentials = $provider()->wait();

    expect($credentials)->toBeInstanceOf(CredentialsInterface::class)
        ->and($credentials->getAccessKeyId())->toBe('AKIA-IMDS-TEMP')
        ->and($credentials->getSecretKey())->toBe('IMDS-SECRET-NEVER-LOG')
        ->and($credentials->getSecurityToken())->toBe('IMDS-SESSION-NEVER-LOG')
        ->and($credentials->getExpiration())->toBe(1893459600)
        ->and($history)->toHaveCount(3)
        ->and($history[0]['request']->getMethod())->toBe('PUT')
        ->and((string) $history[0]['request']->getUri())->toBe('http://169.254.169.254/latest/api/token')
        ->and($history[0]['request']->getHeaderLine('X-aws-ec2-metadata-token-ttl-seconds'))->toBe('21600')
        ->and($history[1]['request']->getMethod())->toBe('GET')
        ->and((string) $history[1]['request']->getUri())->toBe('http://169.254.169.254/latest/meta-data/iam/security-credentials/')
        ->and($history[1]['request']->getHeaderLine('X-aws-ec2-metadata-token'))->toBe('metadata-token-never-log')
        ->and((string) $history[2]['request']->getUri())->toBe('http://169.254.169.254/latest/meta-data/iam/security-credentials/ssl-manager-role')
        ->and($history[2]['request']->getHeaderLine('X-aws-ec2-metadata-token'))->toBe('metadata-token-never-log');

    foreach ($history as $transaction) {
        expect($transaction['options']['allow_redirects'] ?? null)->toBeFalse()
            ->and($transaction['options']['proxy'] ?? null)->toBeNull()
            ->and($transaction['options']['connect_timeout'] ?? null)->toBe(1.0)
            ->and($transaction['options']['timeout'] ?? null)->toBe(2.0);
    }
});

test('IMDS 临时凭证缓存到到期前五分钟并在阈值内刷新', function () {
    $history = [];
    $now = 1893456000;
    $http = awsMetadataFixture([
        new Response(200, [], 'token-1'),
        new Response(200, [], 'role-1'),
        new Response(200, [], awsImdsSuccessJson('2030-01-01T00:10:00Z')),
        new Response(200, [], 'token-2'),
        new Response(200, [], 'role-1'),
        new Response(200, [], awsImdsSuccessJson('2030-01-01T01:00:00Z')),
    ], $history);
    $provider = new AwsImdsCredentialProvider($http, static function () use (&$now): int {
        return $now;
    });

    expect($provider()->wait()->getExpiration())->toBe(1893456600)
        ->and($provider()->wait()->getExpiration())->toBe(1893456600)
        ->and($history)->toHaveCount(3);

    $now += 301;

    expect($provider()->wait()->getExpiration())->toBe(1893459600)
        ->and($history)->toHaveCount(6);
});

test('metadata transport 拒绝非内建 AWS route enum', function () {
    $history = [];
    $http = awsMetadataFixture([], $history);
    $unknownRoute = new class implements CloudMetadataRoute
    {
        public function baseUri(): string
        {
            return 'http://127.0.0.1';
        }

        public function method(): string
        {
            return 'POST';
        }

        public function path(array $parameters = []): string
        {
            return '/arbitrary';
        }

        public function headers(array $context = []): array
        {
            return [];
        }
    };

    expect(fn () => $http->request($unknownRoute))
        ->toThrow(LogicException::class, 'metadata route');
    expect($history)->toBe([]);
});

test('IMDS role 名称和凭证 JSON 严格校验且异常不含上游秘密', function (array $responses) {
    $history = [];
    $http = awsMetadataFixture($responses, $history);
    $provider = new AwsImdsCredentialProvider($http, static fn (): int => 1893456000);

    try {
        $provider()->wait();
        test()->fail('畸形 IMDS 响应必须失败关闭');
    } catch (Throwable $e) {
        expect($e->getMessage())->toBe('AWS IMDSv2 获取临时凭证失败')
            ->and($e->getMessage())->not->toContain('UPSTREAM-SECRET')
            ->and($e->getPrevious())->toBeNull();
    }
})->with([
    'redirect' => [[new Response(302, ['Location' => 'http://public.example/UPSTREAM-SECRET'], 'UPSTREAM-SECRET')]],
    'invalid role' => [[
        new Response(200, [], 'token'),
        new Response(200, [], '../UPSTREAM-SECRET'),
    ]],
    'non-success code' => [[
        new Response(200, [], 'token'),
        new Response(200, [], 'role'),
        new Response(200, [], json_encode([
            'Code' => 'Failure',
            'AccessKeyId' => 'UPSTREAM-SECRET',
            'SecretAccessKey' => 'UPSTREAM-SECRET',
            'Token' => 'UPSTREAM-SECRET',
            'Expiration' => '2030-01-01T01:00:00Z',
        ], JSON_THROW_ON_ERROR)),
    ]],
    'missing session token' => [[
        new Response(200, [], 'token'),
        new Response(200, [], 'role'),
        new Response(200, [], json_encode([
            'Code' => 'Success',
            'AccessKeyId' => 'UPSTREAM-SECRET',
            'SecretAccessKey' => 'UPSTREAM-SECRET',
            'Expiration' => '2030-01-01T01:00:00Z',
        ], JSON_THROW_ON_ERROR)),
    ]],
    'invalid expiration' => [[
        new Response(200, [], 'token'),
        new Response(200, [], 'role'),
        new Response(200, [], awsImdsSuccessJson('UPSTREAM-SECRET')),
    ]],
]);

test('AWS 八个端点均以显式 accesskey 或 IMDS provider 构造 SDK client', function (string $deployerClass, string $kind, string $clientClass) {
    $deployer = new $deployerClass;
    $makeClient = new ReflectionMethod($deployer, 'makeClient');

    $staticClient = $makeClient->invoke($deployer, $kind, [
        'access_key_id' => 'AK',
        'secret_access_key' => 'SK',
    ], 'us-east-1');
    $imdsClient = $makeClient->invoke($deployer, $kind, [
        'auth_method' => 'imds',
    ], 'us-east-1');
    $credentialProvider = new ReflectionProperty(AwsClient::class, 'credentialProvider');
    $staticProvider = $credentialProvider->getValue($staticClient);
    $imdsProvider = $credentialProvider->getValue($imdsClient);

    expect($staticClient)->toBeInstanceOf($clientClass)
        ->and($staticProvider)->toBeCallable()
        ->and($staticProvider()->wait())->toBeInstanceOf(CredentialsInterface::class)
        ->and($imdsClient)->toBeInstanceOf($clientClass)
        ->and($imdsProvider)->toBeInstanceOf(AwsImdsCredentialProvider::class);
})->with([
    'ACM' => [AwsAcmDeployer::class, 'acm', AcmClient::class],
    'IAM' => [AwsIamDeployer::class, 'iam', IamClient::class],
    'ALB' => [AwsAlbDeployer::class, 'elbv2', ElasticLoadBalancingV2Client::class],
    'NLB' => [AwsNlbDeployer::class, 'elbv2', ElasticLoadBalancingV2Client::class],
    'CLB' => [AwsClbDeployer::class, 'elb', ElasticLoadBalancingClient::class],
    'CloudFront' => [AwsCloudFrontDeployer::class, 'cloudfront', CloudFrontClient::class],
    'Amplify' => [AwsAmplifyDeployer::class, 'amplify', AmplifyClient::class],
    'API Gateway' => [AwsApigatewayDeployer::class, 'apigatewayv2', ApiGatewayV2Client::class],
]);

test('AWS client config 拒绝未知认证方式且实现不调用 SDK 默认凭证链', function () {
    $deployer = new AwsAcmDeployer;
    $makeClient = new ReflectionMethod($deployer, 'makeClient');

    expect(fn () => $makeClient->invoke($deployer, 'acm', [
        'auth_method' => 'default-chain',
    ], 'us-east-1'))->toThrow(InvalidArgumentException::class, '不支持的 AWS 认证方式');

    $sources = file_get_contents(dirname(__DIR__, 4).'/Deployers/Aws/BuildsAwsClientConfig.php')
        .file_get_contents(dirname(__DIR__, 4).'/Deployers/Aws/AwsImdsCredentialProvider.php');
    expect($sources)->not->toContain('defaultProvider(')
        ->not->toContain('instanceProfile(');
});
