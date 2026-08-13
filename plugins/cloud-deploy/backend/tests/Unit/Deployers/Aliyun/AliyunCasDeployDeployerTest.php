<?php

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\CreateDeploymentJobRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\CreateDeploymentJobResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\CreateDeploymentJobResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\DescribeDeploymentJobResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\DescribeDeploymentJobResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\ListContactResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\ListContactResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\ListContactResponseBody\contactList;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasDeployDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Plugins\CloudDeploy\Deployers\Contracts\DeployPollPendingException;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（cas kind）+ sleep no-op。 */
function aliyunCasDeployDeployerWith(callable $clientFactory): AliyunCasDeployDeployer
{
    return new class($clientFactory) extends AliyunCasDeployDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }

        protected function sleep(int $seconds): void {}
    };
}

function casCreateJobResponse(?int $jobId): CreateDeploymentJobResponse
{
    return new CreateDeploymentJobResponse(['body' => new CreateDeploymentJobResponseBody($jobId === null ? [] : ['jobId' => $jobId])]);
}

function casDescribeJobResponse(string $status): DescribeDeploymentJobResponse
{
    return new DescribeDeploymentJobResponse(['body' => new DescribeDeploymentJobResponseBody(['status' => $status])]);
}

function casListContactResponse(array $contactIds): ListContactResponse
{
    $list = array_map(fn ($id) => new contactList(['contactId' => $id]), $contactIds);

    return new ListContactResponse(['body' => new ListContactResponseBody(['contactList' => $list])]);
}

test('阿里云 CAS 托管部署走证书服务（storeKind=cas）+ 元信息', function () {
    $deployer = new AliyunCasDeployDeployer;
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('casdeploy');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->certUploader(['region' => 'ap-southeast-1'])->storeKind())->toBe('cas:ap-southeast-1');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('region')->toContain('resource_ids')->toContain('contact_ids');
});

test('bind 把 config.region 传入 CAS client 构造缝', function () {
    $seenRegion = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('createDeploymentJob')->andReturn(casCreateJobResponse(1));
    $cas->shouldReceive('describeDeploymentJob')->andReturn(casDescribeJobResponse('success'));

    $deployer = aliyunCasDeployDeployerWith(function (string $kind, array $credentials) use (&$seenRegion, $cas) {
        $seenRegion = $credentials['region'] ?? null;

        return $cas;
    });
    $deployer->bind('1-ap-southeast-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'region' => 'ap-southeast-1', 'resource_ids' => 'res-1', 'contact_ids' => 'c-1',
    ]);

    expect($seenRegion)->toBe('ap-southeast-1');
});

test('bind 拆数字 certId 调 CreateDeploymentJob（CertIds/ResourceIds/ContactIds/JobType=user）并轮询到 success', function () {
    $createReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('createDeploymentJob')->once()->andReturnUsing(function (CreateDeploymentJobRequest $req) use (&$createReq) {
        $createReq = $req;

        return casCreateJobResponse(555);
    });
    $cas->shouldReceive('describeDeploymentJob')->once()->andReturn(casDescribeJobResponse('success'));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    $deployer->bind('987654-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'resource_ids' => ['res-1', 'res-2'],
        'contact_ids' => ['c-9'],
    ]);

    expect($createReq->certIds)->toBe('987654'); // 数字 certId（非 CertIdentifier 整串）
    expect($createReq->resourceIds)->toBe('res-1,res-2');
    expect($createReq->contactIds)->toBe('c-9');
    expect($createReq->jobType)->toBe('user');
    expect($createReq->name)->toStartWith('clouddeploy_');
});

test('未指定 contact_ids 时调 ListContact 取首个联系人', function () {
    $createReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('listContact')->once()->andReturn(casListContactResponse([42, 43]));
    $cas->shouldReceive('createDeploymentJob')->once()->andReturnUsing(function (CreateDeploymentJobRequest $req) use (&$createReq) {
        $createReq = $req;

        return casCreateJobResponse(1);
    });
    $cas->shouldReceive('describeDeploymentJob')->once()->andReturn(casDescribeJobResponse('success'));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['resource_ids' => 'res-1']);

    expect($createReq->contactIds)->toBe('42'); // 首个联系人
});

test('resource_ids 支持换行/逗号分隔字符串，归一去重后逗号拼接', function () {
    $createReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('listContact')->andReturn(casListContactResponse([1]));
    $cas->shouldReceive('createDeploymentJob')->andReturnUsing(function (CreateDeploymentJobRequest $req) use (&$createReq) {
        $createReq = $req;

        return casCreateJobResponse(1);
    });
    $cas->shouldReceive('describeDeploymentJob')->andReturn(casDescribeJobResponse('success'));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'resource_ids' => "r-1\nr-2, r-2 ,\nr-3",
    ]);

    expect($createReq->resourceIds)->toBe('r-1,r-2,r-3');
});

test('轮询遇 editing 状态抛业务错误', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('createDeploymentJob')->andReturn(casCreateJobResponse(7));
    $cas->shouldReceive('describeDeploymentJob')->once()->andReturn(casDescribeJobResponse('editing'));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'resource_ids' => 'res-1', 'contact_ids' => 'c-1',
    ]))->toThrow(RuntimeException::class, '状态异常');
});

test('缺 resource_ids 配置抛业务错误', function () {
    $deployer = aliyunCasDeployDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], []))
        ->toThrow(RuntimeException::class, '缺少配置 resource_ids');
});

test('bind 轮询遇 error 终态 → 抛 DeployBusinessException（终态失败非成功，G5 通知可达）', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('createDeploymentJob')->andReturn(casCreateJobResponse(8));
    $cas->shouldReceive('describeDeploymentJob')->once()->andReturn(casDescribeJobResponse('error'));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'resource_ids' => 'res-1', 'contact_ids' => 'c-1',
    ]))->toThrow(DeployBusinessException::class, '终态失败');
});

test('G2 resumePoll 遇 error 终态 → 抛 DeployBusinessException（履 ResumesRemoteJob 契约：清 pending + 终态）', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('describeDeploymentJob')->once()->andReturn(casDescribeJobResponse('error'));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    expect(fn () => $deployer->resumePoll('job-err', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], []))
        ->toThrow(DeployBusinessException::class, '终态失败');
});

test('G2 bind 首查未终态 → 抛 DeployPollPendingException 携 jobId（压窗 + 重试续查）', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('createDeploymentJob')->andReturn(casCreateJobResponse(777));
    $cas->shouldReceive('describeDeploymentJob')->once()->andReturn(casDescribeJobResponse('deploying'));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['resource_ids' => 'res-1', 'contact_ids' => 'c-1']);
        expect(false)->toBeTrue('应抛 poll_pending');
    } catch (DeployPollPendingException $e) {
        expect($e->remoteJobId)->toBe('777');
    }
});

test('G2 resumePoll 终态 success 正常返回（续查同一 jobId、不重建任务）', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('createDeploymentJob')->never(); // 不重建云端任务
    $cas->shouldReceive('describeDeploymentJob')->once()->andReturn(casDescribeJobResponse('success'));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    $deployer->resumePoll('job-42', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], []);
    expect(true)->toBeTrue(); // 无异常即收敛
});

test('G2 resumePoll 仍处理中 → 抛 DeployPollPendingException 同 jobId', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('describeDeploymentJob')->andReturn(casDescribeJobResponse('deploying'));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    try {
        $deployer->resumePoll('job-99', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], []);
        expect(false)->toBeTrue();
    } catch (DeployPollPendingException $e) {
        expect($e->remoteJobId)->toBe('job-99');
    }
});

test('G2 resumePoll 遇 editing → 抛业务错误（终态失败）', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('describeDeploymentJob')->once()->andReturn(casDescribeJobResponse('editing'));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    expect(fn () => $deployer->resumePoll('job-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], []))
        ->toThrow(DeployBusinessException::class);
});

test('pollBudget bind 最坏耗时 ≤50s（T=read+connect 之和，darabonba 合成总超时）', function () {
    expect((new AliyunCasDeployDeployer)->pollBudget()->worstCaseBindSeconds())->toBeLessThanOrEqual(50);
});

test('bind SDK 抛 TeaError 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('createDeploymentJob')->andThrow(new TeaError([
        'code' => 'Forbidden.RAM',
        'message' => 'denied',
        'data' => ['Code' => 'Forbidden.RAM', 'Message' => 'access denied'],
    ]));

    $deployer = aliyunCasDeployDeployerWith(fn () => $cas);
    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-LEAK', 'access_key_secret' => 'SK-LEAK'], [
            'resource_ids' => 'res-1', 'contact_ids' => 'c-1',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Forbidden.RAM');
        expect($e->getMessage())->not->toContain('AK-LEAK')->not->toContain('SK-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});
