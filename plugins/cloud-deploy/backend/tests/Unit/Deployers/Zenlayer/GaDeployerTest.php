<?php

use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerApiException;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerGaDeployer;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝（按 $kind 返回 mock）+ sleep no-op（轮询不真等）。
 * uploader 经 certUploader() 复用同一 makeClient('zga')，故 mock zga 即覆盖上传 + 绑定路径。
 */
function zenlayerGaDeployerWith(callable $clientFactory): ZenlayerGaDeployer
{
    return new class($clientFactory) extends ZenlayerGaDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }

        protected function sleep(int $seconds): void {}
    };
}

function zenlayerGaCreds(): array
{
    return ['access_key_id' => 'AK', 'access_key_password' => 'PWD'];
}

test('Zenlayer ZGA：证书服务型（usesRemoteCertStore + storeKind zenlayer_zga + 元信息）', function () {
    $deployer = new ZenlayerGaDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('zenlayer_zga');
    expect($deployer->provider())->toBe('zenlayer');
    expect($deployer->product())->toBe('ga');
    expect($deployer->label())->toBe('Zenlayer 全球加速 ZGA');
});

test('pollBudget bind 最坏耗时 ≤50s（G2 计算断言）', function () {
    expect((new ZenlayerGaDeployer)->pollBudget()->worstCaseBindSeconds())->toBeLessThanOrEqual(50);
});

test('uploader.upload 走 zga 服务 CreateCertificate 返回 certificateId', function () {
    $captured = null;
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')
        ->once()
        ->andReturnUsing(function (string $action, array $body) use (&$captured) {
            $captured = compact('action', 'body');

            return ['certificateId' => 'cert-zga'];
        });

    $deployer = zenlayerGaDeployerWith(fn (string $kind) => $kind === 'zga' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', zenlayerGaCreds());

    expect($id)->toBe('cert-zga');
    expect($captured['action'])->toBe('CreateCertificate');
    expect($captured['body']['certificateContent'])->toContain('CERTPEM')->toContain('CHAINPEM');
});

test('bind：加速器既有证书非目标 → ModifyAcceleratorCertificate + 轮询 Accelerating', function () {
    $calls = [];
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $action, array $body) use (&$calls) {
        $calls[] = compact('action', 'body');
        if ($action === 'DescribeAccelerators') {
            static $n = 0;
            $n++;
            // 第一次查询：返回旧证书；轮询：返回 Accelerating
            if ($n === 1) {
                return ['dataSet' => [['acceleratorId' => 'ga-1', 'certificate' => ['certificateId' => 'old-cert']]]];
            }

            return ['dataSet' => [['acceleratorId' => 'ga-1', 'acceleratorStatus' => 'Accelerating']]];
        }

        return [];
    });

    $deployer = zenlayerGaDeployerWith(fn () => $client);
    $deployer->bind('cert-NEW', zenlayerGaCreds(), ['accelerator_id' => 'ga-1']);

    $modify = collect($calls)->firstWhere('action', 'ModifyAcceleratorCertificate');
    expect($modify)->not->toBeNull();
    expect($modify['body']['acceleratorId'])->toBe('ga-1');
    expect($modify['body']['certificateId'])->toBe('cert-NEW');
});

test('bind：加速器已绑定该证书 → 跳过 ModifyAcceleratorCertificate（仍轮询）', function () {
    $calls = [];
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $action) use (&$calls) {
        $calls[] = $action;
        if ($action === 'DescribeAccelerators') {
            static $n = 0;
            $n++;
            if ($n === 1) {
                return ['dataSet' => [['acceleratorId' => 'ga-1', 'certificate' => ['certificateId' => 'cert-SAME']]]];
            }

            return ['dataSet' => [['acceleratorId' => 'ga-1', 'acceleratorStatus' => 'Accelerating']]];
        }

        return [];
    });

    $deployer = zenlayerGaDeployerWith(fn () => $client);
    $deployer->bind('cert-SAME', zenlayerGaCreds(), ['accelerator_id' => 'ga-1']);

    expect($calls)->not->toContain('ModifyAcceleratorCertificate');
});

test('bind：加速器不存在 → 业务失败', function () {
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andReturn(['dataSet' => []]);

    $deployer = zenlayerGaDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('cert-NEW', zenlayerGaCreds(), ['accelerator_id' => 'ga-1']))
        ->toThrow(RuntimeException::class, '未找到 Zenlayer 加速器');
});

test('bind：轮询返回 AccelerateFailure → 业务失败', function () {
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $action) {
        if ($action === 'DescribeAccelerators') {
            static $n = 0;
            $n++;
            if ($n === 1) {
                return ['dataSet' => [['acceleratorId' => 'ga-1', 'certificate' => ['certificateId' => 'old']]]];
            }

            return ['dataSet' => [['acceleratorId' => 'ga-1', 'acceleratorStatus' => 'AccelerateFailure']]];
        }

        return [];
    });

    $deployer = zenlayerGaDeployerWith(fn () => $client);
    expect(fn () => $deployer->bind('cert-NEW', zenlayerGaCreds(), ['accelerator_id' => 'ga-1']))
        ->toThrow(RuntimeException::class, '状态异常');
});

test('缺 accelerator_id 配置抛业务错误', function () {
    $deployer = zenlayerGaDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', zenlayerGaCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 accelerator_id');
});

test('bind SDK 抛 ZenlayerApiException 时脱敏重抛（无 AK/PWD、不挂 previous）', function () {
    $client = Mockery::mock(ZenlayerRestClient::class);
    $client->shouldReceive('call')->andThrow(new ZenlayerApiException('AUTH_FAILED', 'invalid signature'));

    $deployer = zenlayerGaDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_password' => 'PWD-SECRET-ABC'], ['accelerator_id' => 'ga-1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('AUTH_FAILED');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('PWD-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});
