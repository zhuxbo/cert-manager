<?php

use Plugins\CloudDeploy\Deployers\Wangsu\WangsuApiException;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuCdnProDeployer;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入 mock；now() 固定时间戳（验 AES 向量）；sleep no-op（免真实等待）。
 */
function wangsuCdnProDeployerWith(callable $clientFactory, int $now = 1700000000): WangsuCdnProDeployer
{
    return new class($clientFactory, $now) extends WangsuCdnProDeployer
    {
        public function __construct(private $factory, private int $fixedNow) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }

        protected function now(): int
        {
            return $this->fixedNow;
        }

        protected function sleep(int $seconds): void {}
    };
}

const WANGSU_CDNPRO_CREDS = ['access_key_id' => 'AK', 'access_key_secret' => 'SK', 'api_key' => 'API-KEY-TEST'];

const WANGSU_CDNPRO_PEM = ['cert' => 'CERTPEM', 'key' => "-----BEGIN PRIVATE KEY-----\nMIIBVQ==\n-----END PRIVATE KEY-----\n", 'chain' => 'CHAINPEM'];

test('网宿云 CDN Pro 为内联型（usesRemoteCertStore=false，无 uploader）+ 元信息', function () {
    $deployer = new WangsuCdnProDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->provider())->toBe('wangsu');
    expect($deployer->product())->toBe('cdnpro');
    expect($deployer->label())->toBe('网宿云 CDN Pro');
});

test('encryptPrivateKey 固定输入 → 固定输出（AES-128-CBC golden vector，ts=1700000000）', function () {
    // 经子类暴露 protected encryptPrivateKey 验权威向量（与 certimate Go encryptPrivateKey 逐字节对齐）。
    $deployer = new class extends WangsuCdnProDeployer
    {
        public function probeEncrypt(string $key, string $apiKey, int $ts): string
        {
            return $this->encryptPrivateKey($key, $apiKey, $ts);
        }

        protected function makeClient(string $kind, array $credentials): object
        {
            return new stdClass;
        }
    };

    $enc = $deployer->probeEncrypt(WANGSU_CDNPRO_PEM['key'], 'API-KEY-TEST', 1700000000);

    expect($enc)->toBe('COSBXCbdFgmqA/iKcj/Yl7hwFFm2lGD78Gkqf7fZDwdtv9MdJtI6tLpGhDMZo6dL8RZHSxCmxWITrARSxpeFoA==');
});

test('bind 完整流程：getHostname → 私钥加密 → createCertificate → createDeploymentTask → 轮询成功', function () {
    $calls = [];
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('getCdnProHostnameDetail')->once()->with('cdnpro.example.com')->andReturn(['hostname' => 'cdnpro.example.com']);
    $client->shouldReceive('createCdnProCertificate')
        ->once()
        ->andReturnUsing(function (string $name, array $newVersion, int $ts) use (&$calls) {
            $calls['create'] = compact('name', 'newVersion', 'ts');

            return ['certId' => 'cert-obj-1', 'version' => 1];
        });
    $client->shouldReceive('createCdnProDeploymentTask')
        ->once()
        ->andReturnUsing(function (string $name, string $target, string $certId, int $version, string $webhook) use (&$calls) {
            $calls['task'] = compact('name', 'target', 'certId', 'version', 'webhook');

            return 'task-1';
        });
    $client->shouldReceive('getCdnProDeploymentTaskDetail')->once()->with('task-1')->andReturn(['status' => 'succeeded', 'finishTime' => '']);

    $deployer = wangsuCdnProDeployerWith(fn () => $client);
    $deployer->bind(WANGSU_CDNPRO_PEM, WANGSU_CDNPRO_CREDS, ['domain' => 'cdnpro.example.com', 'environment' => 'production']);

    // 证书内容：明文证书 + AES 加密私钥（用固定 ts 算出的权威向量）
    expect($calls['create']['newVersion']['certificate'])->toBe('CERTPEM');
    expect($calls['create']['newVersion']['privateKey'])->toBe('COSBXCbdFgmqA/iKcj/Yl7hwFFm2lGD78Gkqf7fZDwdtv9MdJtI6tLpGhDMZo6dL8RZHSxCmxWITrARSxpeFoA==');
    expect($calls['create']['ts'])->toBe(1700000000);
    // 部署任务：target=环境，certId/version 来自创建，webhook 空
    expect($calls['task']['target'])->toBe('production');
    expect($calls['task']['certId'])->toBe('cert-obj-1');
    expect($calls['task']['version'])->toBe(1);
    expect($calls['task']['webhook'])->toBe('');
});

test('bind 填 certificate_id 走更新（updateCdnProCertificate，用其返回的版本号）', function () {
    $taskVersion = null;
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('getCdnProHostnameDetail')->andReturn(['hostname' => 'd.example.com']);
    $client->shouldReceive('createCdnProCertificate')->never();
    $client->shouldReceive('updateCdnProCertificate')
        ->once()
        ->with('existing-cert-99', Mockery::type('string'), Mockery::type('array'), 1700000000)
        ->andReturn(['certId' => 'existing-cert-99', 'version' => 7]);
    $client->shouldReceive('createCdnProDeploymentTask')
        ->once()
        ->andReturnUsing(function (string $name, string $target, string $certId, int $version) use (&$taskVersion) {
            $taskVersion = ['certId' => $certId, 'version' => $version];

            return 'task-2';
        });
    $client->shouldReceive('getCdnProDeploymentTaskDetail')->andReturn(['status' => 'succeeded', 'finishTime' => '']);

    $deployer = wangsuCdnProDeployerWith(fn () => $client);
    $deployer->bind(WANGSU_CDNPRO_PEM, WANGSU_CDNPRO_CREDS, [
        'domain' => 'd.example.com', 'environment' => 'staging', 'certificate_id' => 'existing-cert-99',
    ]);

    expect($taskVersion)->toBe(['certId' => 'existing-cert-99', 'version' => 7]);
});

test('bind 传 webhook_id 时部署任务带上 webhook', function () {
    $webhook = null;
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('getCdnProHostnameDetail')->andReturn(['hostname' => 'd.example.com']);
    $client->shouldReceive('createCdnProCertificate')->andReturn(['certId' => 'c1', 'version' => 1]);
    $client->shouldReceive('createCdnProDeploymentTask')
        ->andReturnUsing(function (string $n, string $t, string $c, int $v, string $wh) use (&$webhook) {
            $webhook = $wh;

            return 'task-3';
        });
    $client->shouldReceive('getCdnProDeploymentTaskDetail')->andReturn(['status' => 'succeeded', 'finishTime' => '']);

    $deployer = wangsuCdnProDeployerWith(fn () => $client);
    $deployer->bind(WANGSU_CDNPRO_PEM, WANGSU_CDNPRO_CREDS, [
        'domain' => 'd.example.com', 'environment' => 'production', 'webhook_id' => 'wh-xyz',
    ]);

    expect($webhook)->toBe('wh-xyz');
});

test('轮询：finishTime 非空也视为完成（status 非 succeeded 时）', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('getCdnProHostnameDetail')->andReturn(['hostname' => 'd.com']);
    $client->shouldReceive('createCdnProCertificate')->andReturn(['certId' => 'c1', 'version' => 1]);
    $client->shouldReceive('createCdnProDeploymentTask')->andReturn('task-4');
    // 第一次 processing，第二次 finishTime 非空
    $client->shouldReceive('getCdnProDeploymentTaskDetail')
        ->andReturn(['status' => 'processing', 'finishTime' => ''], ['status' => 'processing', 'finishTime' => '2023-11-14T00:00:00Z']);

    $deployer = wangsuCdnProDeployerWith(fn () => $client);
    $deployer->bind(WANGSU_CDNPRO_PEM, WANGSU_CDNPRO_CREDS, ['domain' => 'd.com', 'environment' => 'production']);

    expect(true)->toBeTrue();
});

test('轮询遇 status=failed 抛业务错误', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('getCdnProHostnameDetail')->andReturn(['hostname' => 'd.com']);
    $client->shouldReceive('createCdnProCertificate')->andReturn(['certId' => 'c1', 'version' => 1]);
    $client->shouldReceive('createCdnProDeploymentTask')->andReturn('task-5');
    $client->shouldReceive('getCdnProDeploymentTaskDetail')->andReturn(['status' => 'failed', 'finishTime' => '']);

    $deployer = wangsuCdnProDeployerWith(fn () => $client);

    expect(fn () => $deployer->bind(WANGSU_CDNPRO_PEM, WANGSU_CDNPRO_CREDS, ['domain' => 'd.com', 'environment' => 'production']))
        ->toThrow(RuntimeException::class, '部署任务失败');
});

test('轮询超过最大次数仍未完成抛等待超时', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('getCdnProHostnameDetail')->andReturn(['hostname' => 'd.com']);
    $client->shouldReceive('createCdnProCertificate')->andReturn(['certId' => 'c1', 'version' => 1]);
    $client->shouldReceive('createCdnProDeploymentTask')->andReturn('task-6');
    // 永远 processing
    $client->shouldReceive('getCdnProDeploymentTaskDetail')->andReturn(['status' => 'processing', 'finishTime' => '']);

    // maxPollAttempts 缩到 2 加速测试
    $deployer = new class(fn () => $client) extends WangsuCdnProDeployer
    {
        public function __construct(private $factory)
        {
            $this->maxPollAttempts = 2;
        }

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }

        protected function now(): int
        {
            return 1700000000;
        }

        protected function sleep(int $seconds): void {}
    };

    expect(fn () => $deployer->bind(WANGSU_CDNPRO_PEM, WANGSU_CDNPRO_CREDS, ['domain' => 'd.com', 'environment' => 'production']))
        ->toThrow(RuntimeException::class, '未在等待窗口内完成');
});

test('缺 api_key 凭证抛业务错误（私钥加密强依赖）', function () {
    $deployer = wangsuCdnProDeployerWith(fn () => new stdClass);

    expect(fn () => $deployer->bind(WANGSU_CDNPRO_PEM, ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['domain' => 'd.com', 'environment' => 'production']))
        ->toThrow(RuntimeException::class, 'API Key');
});

test('缺 domain / environment 配置抛业务错误', function () {
    $deployer = wangsuCdnProDeployerWith(fn () => new stdClass);

    expect(fn () => $deployer->bind(WANGSU_CDNPRO_PEM, WANGSU_CDNPRO_CREDS, ['environment' => 'production']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
    expect(fn () => $deployer->bind(WANGSU_CDNPRO_PEM, WANGSU_CDNPRO_CREDS, ['domain' => 'd.com']))
        ->toThrow(RuntimeException::class, '缺少配置 environment');
});

test('证书接口未返回 certId 抛业务错误', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('getCdnProHostnameDetail')->andReturn(['hostname' => 'd.com']);
    $client->shouldReceive('createCdnProCertificate')->andReturn(['certId' => '', 'version' => 1]);

    $deployer = wangsuCdnProDeployerWith(fn () => $client);

    expect(fn () => $deployer->bind(WANGSU_CDNPRO_PEM, WANGSU_CDNPRO_CREDS, ['domain' => 'd.com', 'environment' => 'production']))
        ->toThrow(RuntimeException::class, '未返回证书 ID');
});

test('部署任务未返回任务 ID 抛业务错误', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('getCdnProHostnameDetail')->andReturn(['hostname' => 'd.com']);
    $client->shouldReceive('createCdnProCertificate')->andReturn(['certId' => 'c1', 'version' => 1]);
    $client->shouldReceive('createCdnProDeploymentTask')->andReturn('');

    $deployer = wangsuCdnProDeployerWith(fn () => $client);

    expect(fn () => $deployer->bind(WANGSU_CDNPRO_PEM, WANGSU_CDNPRO_CREDS, ['domain' => 'd.com', 'environment' => 'production']))
        ->toThrow(RuntimeException::class, '未返回任务 ID');
});

test('bind SDK 抛 WangsuApiException 时脱敏重抛（无 AK/SK/apiKey、不挂 previous）', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('getCdnProHostnameDetail')->andThrow(new WangsuApiException('404', 'hostname not found'));

    $deployer = wangsuCdnProDeployerWith(fn () => $client);

    try {
        $deployer->bind(WANGSU_CDNPRO_PEM, ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC', 'api_key' => 'APIKEY-LEAK-123'], [
            'domain' => 'x.example.com', 'environment' => 'production',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('404')->toContain('hostname not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC')->not->toContain('APIKEY-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        $haystack = $e->getMessage()."\n".$e->getTraceAsString();
        expect($haystack)->not->toContain('SK-SECRET-ABC');
    }
});

test('bind 网络类异常时脱敏只暴露类名（无 apiKey、不挂 previous）', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('getCdnProHostnameDetail')->andThrow(new RuntimeException(
        'cURL error 7: connect open.chinanetcenter.com APIKEY-LEAK-456',
    ));

    $deployer = wangsuCdnProDeployerWith(fn () => $client);

    try {
        $deployer->bind(WANGSU_CDNPRO_PEM, ['access_key_id' => 'AK', 'access_key_secret' => 'SK', 'api_key' => 'APIKEY-LEAK-456'], [
            'domain' => 'x.example.com', 'environment' => 'production',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-456');
        expect($e->getMessage())->toContain('网宿云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});
