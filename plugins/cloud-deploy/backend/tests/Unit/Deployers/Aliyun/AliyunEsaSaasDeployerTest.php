<?php

use AlibabaCloud\SDK\ESA\V20240910\ESA;
use AlibabaCloud\SDK\ESA\V20240910\Models\ListCustomHostnamesResponse;
use AlibabaCloud\SDK\ESA\V20240910\Models\ListCustomHostnamesResponseBody;
use AlibabaCloud\SDK\ESA\V20240910\Models\ListCustomHostnamesResponseBody\hostnames;
use AlibabaCloud\SDK\ESA\V20240910\Models\UpdateCustomHostnameRequest;
use AlibabaCloud\SDK\ESA\V20240910\Models\UpdateCustomHostnameResponse;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunEsaSaasDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（esa kind）。 */
function aliyunEsaSaasDeployerWith(callable $clientFactory): AliyunEsaSaasDeployer
{
    return new class($clientFactory) extends AliyunEsaSaasDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

/** @param  list<array{hostnameId:int,hostname:string,status?:string}>  $items */
function esaHostnamesResponse(array $items): ListCustomHostnamesResponse
{
    $list = array_map(fn ($i) => new hostnames([
        'hostnameId' => $i['hostnameId'],
        'hostname' => $i['hostname'],
        'status' => $i['status'] ?? 'active',
    ]), $items);

    return new ListCustomHostnamesResponse(['body' => new ListCustomHostnamesResponseBody(['hostnames' => $list])]);
}

test('阿里云 ESA SaaS 走证书服务（storeKind=cas）+ 元信息', function () {
    $deployer = new AliyunEsaSaasDeployer;
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('esasaas');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('site_id')->toContain('domain');
});

test('bind 找到精确域名后调 UpdateCustomHostname（CertType=cas、CasId 数字 certId、CasRegion、SslFlag=on）', function () {
    $updateReq = null;
    $esa = Mockery::mock(ESA::class);
    $esa->shouldReceive('listCustomHostnames')->once()->andReturn(esaHostnamesResponse([
        ['hostnameId' => 11, 'hostname' => 'other.example.com'],
        ['hostnameId' => 22, 'hostname' => 'saas.example.com'],
    ]));
    $esa->shouldReceive('updateCustomHostname')->once()->andReturnUsing(function (UpdateCustomHostnameRequest $req) use (&$updateReq) {
        $updateReq = $req;

        return new UpdateCustomHostnameResponse;
    });

    $deployer = aliyunEsaSaasDeployerWith(fn () => $esa);
    $deployer->bind('987654-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'site_id' => '100', 'domain' => 'saas.example.com',
    ]);

    expect($updateReq->hostnameId)->toBe(22);
    expect($updateReq->sslFlag)->toBe('on');
    expect($updateReq->certType)->toBe('cas');
    expect($updateReq->casId)->toBe(987654);
    expect($updateReq->casRegion)->toBe('cn-hangzhou');
});

test('跳过 pending/conflicted/offline 状态域名（即使同名）', function () {
    $esa = Mockery::mock(ESA::class);
    $esa->shouldReceive('listCustomHostnames')->once()->andReturn(esaHostnamesResponse([
        ['hostnameId' => 7, 'hostname' => 'saas.example.com', 'status' => 'pending'],
    ]));
    $esa->shouldNotReceive('updateCustomHostname');

    $deployer = aliyunEsaSaasDeployerWith(fn () => $esa);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'site_id' => '100', 'domain' => 'saas.example.com',
    ]))->toThrow(RuntimeException::class, '未找到 ESA SaaS 域名');
});

test('site_id 非数字抛业务错误', function () {
    $deployer = aliyunEsaSaasDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'site_id' => 'abc', 'domain' => 'd.example.com',
    ]))->toThrow(RuntimeException::class, 'site_id');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = aliyunEsaSaasDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'site_id' => '100',
    ]))->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 TeaError 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $esa = Mockery::mock(ESA::class);
    $esa->shouldReceive('listCustomHostnames')->andThrow(new TeaError([
        'code' => 'InvalidSite',
        'message' => 'bad site',
        'data' => ['Code' => 'InvalidSite', 'Message' => 'site not found'],
    ]));

    $deployer = aliyunEsaSaasDeployerWith(fn () => $esa);
    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-LEAK', 'access_key_secret' => 'SK-LEAK'], [
            'site_id' => '100', 'domain' => 'd.example.com',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('InvalidSite');
        expect($e->getMessage())->not->toContain('AK-LEAK')->not->toContain('SK-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});
