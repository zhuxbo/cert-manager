<?php

use Plugins\CloudDeploy\Deployers\Contracts\CertificateDeliveryMode;
use Plugins\CloudDeploy\Deployers\Contracts\DeployPollPendingException;
use Plugins\CloudDeploy\Deployers\Contracts\PersistsOpaqueInlineJobId;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslUpdateDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslUploader;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\DescribeHostUpdateRecordDetailRequest;
use TencentCloud\Ssl\V20191205\Models\DescribeHostUpdateRecordDetailResponse;
use TencentCloud\Ssl\V20191205\Models\UpdateCertificateInstanceRequest;
use TencentCloud\Ssl\V20191205\Models\UpdateCertificateInstanceResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（ssl kind）。 */
function tencentSslUpdateDeployerWith(callable $clientFactory): TencentSslUpdateDeployer
{
    return new class($clientFactory) extends TencentSslUpdateDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            $client = ($this->factory)($kind, $credentials);
            if ($kind === 'ssl' && $client instanceof SslClient) {
                $client->shouldReceive('DescribeHostUpdateRecordDetail')
                    ->byDefault()->andReturn(sslUpdateDetailResponse(1, 1));
            }

            return $client;
        }

        protected function sleep(int $seconds): void {}
    };
}

function sslUpdateDetailResponse(?int $total, int $success = 0, int $failed = 0): DescribeHostUpdateRecordDetailResponse
{
    $response = new DescribeHostUpdateRecordDetailResponse;
    $data = ['SuccessTotalCount' => $success, 'FailedTotalCount' => $failed, 'RequestId' => 'r'];
    if ($total !== null) {
        $data['TotalCount'] = $total;
    }
    $response->deserialize($data);

    return $response;
}

function sslUpdateResponse(): UpdateCertificateInstanceResponse
{
    $resp = new UpdateCertificateInstanceResponse;
    $resp->deserialize(['DeployRecordId' => 1, 'DeployStatus' => 1, 'RequestId' => 'r']);

    return $resp;
}

function sslUploadUpdateDetailResponse(int $running = 0, int $success = 0, int $failed = 0, int $total = 1): array
{
    return [
        'DeployRecordDetail' => [[
            'RunningTotalCount' => $running,
            'SuccessTotalCount' => $success,
            'FailedTotalCount' => $failed,
            'TotalCount' => $total,
        ]],
    ];
}

test('腾讯云 SSL 一键更新走证书服务（storeKind=tencent_ssl）+ 元信息', function () {
    $deployer = new TencentSslUpdateDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('ssl-update');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->toBeInstanceOf(TencentSslUploader::class);
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('certificate_id')->toContain('resource_products')->toContain('resource_regions')->toContain('is_replaced');
    expect(collect($deployer->configSchema())->firstWhere('key', 'is_replaced')['default'])->toBeFalse();
    expect($deployer->certificateDeliveryMode([]))->toBe(CertificateDeliveryMode::RemoteStore);
    expect($deployer->certificateDeliveryMode(['is_replaced' => true]))->toBe(CertificateDeliveryMode::Inline);
    expect($deployer)->toBeInstanceOf(PersistsOpaqueInlineJobId::class);
});

test('is_replaced opaque job id 只接受可安全转 int 的正十进制并返回 canonical 值', function (string $raw, ?string $expected) {
    expect((new TencentSslUpdateDeployer)->canonicalOpaqueInlineJobId($raw))->toBe($expected);
})->with([
    '正数' => ['9', '9'],
    '前导零规范化' => ['0009', '9'],
    '零' => ['0', null],
    '负数' => ['-1', null],
    '空白' => [' 9', null],
    '敏感文本' => ['job-PRIVATE-KEY', null],
    '整数溢出' => [(string) PHP_INT_MAX.'0', null],
]);

test('is_replaced=true 用 callJson 内联完整链和私钥，并轮询上传更新记录', function () {
    $calls = [];
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->twice()->andReturnUsing(function (string $action, string $body) use (&$calls): array {
        $calls[] = [$action, json_decode($body, true, flags: JSON_THROW_ON_ERROR)];

        return match ($action) {
            'UploadUpdateCertificateInstance' => ['DeployStatus' => 1, 'DeployRecordId' => 9],
            'DescribeHostUploadUpdateRecordDetail' => sslUploadUpdateDetailResponse(success: 1),
        };
    });

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    $deployer->bind(['cert' => 'LEAF', 'chain' => 'CHAIN', 'key' => 'PRIVATE-KEY'], ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'is_replaced' => true,
        'certificate_id' => 'old-cert-id',
        'resource_products' => 'cdn,clb,waf',
        'resource_regions' => 'ap-guangzhou,ap-shanghai',
    ]);

    expect($calls[0])->toBe(['UploadUpdateCertificateInstance', [
        'OldCertificateId' => 'old-cert-id',
        'CertificatePublicKey' => "LEAF\nCHAIN",
        'CertificatePrivateKey' => 'PRIVATE-KEY',
        'ResourceTypes' => ['cdn', 'clb', 'waf'],
        'ResourceTypesRegions' => [
            ['ResourceType' => 'clb', 'Regions' => ['ap-guangzhou', 'ap-shanghai']],
            ['ResourceType' => 'waf', 'Regions' => ['ap-guangzhou', 'ap-shanghai']],
        ],
    ]]);
    expect($calls[1])->toBe(['DescribeHostUploadUpdateRecordDetail', ['DeployRecordId' => 9, 'Limit' => 200]]);
});

test('is_replaced=true 的 resumePoll 使用上传更新记录查询且不重建云端任务', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->once()->with('DescribeHostUploadUpdateRecordDetail', Mockery::on(function (string $body): bool {
        return json_decode($body, true, flags: JSON_THROW_ON_ERROR) === ['DeployRecordId' => 9, 'Limit' => 200];
    }))->andReturn(sslUploadUpdateDetailResponse(success: 1));
    $ssl->shouldNotReceive('UpdateCertificateInstance');

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    $deployer->resumePoll('9', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['is_replaced' => true]);
});

test('is_replaced=true 首查 pending 后 resumePoll 续查同一上传更新记录', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->times(3)->andReturnUsing(function (string $action): array {
        static $detailCalls = 0;
        if ($action === 'UploadUpdateCertificateInstance') {
            return ['DeployStatus' => 1, 'DeployRecordId' => 9];
        }
        expect($action)->toBe('DescribeHostUploadUpdateRecordDetail');
        $detailCalls++;

        return $detailCalls === 1 ? sslUploadUpdateDetailResponse(running: 1, total: 1) : sslUploadUpdateDetailResponse(success: 1);
    });

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    try {
        $deployer->bind(['cert' => 'LEAF', 'chain' => 'CHAIN', 'key' => 'KEY'], ['secret_id' => 'AK', 'secret_key' => 'SK'], [
            'is_replaced' => true, 'certificate_id' => 'old', 'resource_products' => 'cdn',
        ]);
        expect(false)->toBeTrue('应抛 poll_pending');
    } catch (DeployPollPendingException $e) {
        expect($e->remoteJobId)->toBe('9');
    }
    $deployer->resumePoll('9', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['is_replaced' => true]);
});

test('is_replaced=true 聚合详情中任一失败子任务即终态失败', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->once()->andReturn([
        'DeployRecordDetail' => [
            ['RunningTotalCount' => 0, 'SuccessTotalCount' => 1, 'FailedTotalCount' => 0, 'TotalCount' => 1],
            ['RunningTotalCount' => 0, 'SuccessTotalCount' => 0, 'FailedTotalCount' => 1, 'TotalCount' => 1],
        ],
    ]);

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    expect(fn () => $deployer->resumePoll('9', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['is_replaced' => true]))
        ->toThrow(RuntimeException::class, '腾讯云证书部署任务存在失败子任务（成功 1，失败 1，共 2）');
});

test('is_replaced=true 的 callJson 畸形响应和上游 Message 均以固定安全错误 fail closed', function (array $response) {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->once()->andReturn($response);

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    try {
        $deployer->bind(['cert' => 'LEAF', 'chain' => 'CHAIN', 'key' => 'PRIVATE-KEY'], ['secret_id' => 'AK-LEAK', 'secret_key' => 'SK-LEAK'], [
            'is_replaced' => true, 'certificate_id' => 'old', 'resource_products' => 'cdn',
        ]);
        expect(false)->toBeTrue('应拒绝畸形响应');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('腾讯云上传更新证书任务响应异常');
        expect($e->getMessage())->not->toContain('PRIVATE-KEY')->not->toContain('AK-LEAK')->not->toContain('SK-LEAK')->not->toContain('upstream-secret');
    }
})->with([
    '缺 DeployRecordId' => [['DeployStatus' => 1]],
    '非成功状态' => [['DeployStatus' => 0, 'DeployRecordId' => 9]],
    '上游 Message' => [['DeployStatus' => 1, 'DeployRecordId' => 9, 'Message' => 'upstream-secret']],
    'Response Error' => [['Error' => ['Code' => 'InternalError', 'Message' => 'upstream-secret']]],
    '未解包 Response Error' => [['Response' => ['Error' => ['Code' => 'InternalError', 'Message' => 'upstream-secret']]]],
]);

test('is_replaced=true 的 callJson 标量响应固定失败且不回显内容', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->once()->andReturn('upstream PRIVATE-KEY');

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    expect(fn () => $deployer->bind(['cert' => 'LEAF', 'chain' => 'CHAIN', 'key' => 'PRIVATE-KEY'], ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'is_replaced' => true, 'certificate_id' => 'old', 'resource_products' => 'cdn',
    ]))->toThrow(RuntimeException::class, '腾讯云上传更新证书任务响应异常');
});

test('is_replaced=true 的 callJson SDK 异常丢弃上游敏感 Message 且不挂 previous', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->once()->andThrow(new TencentCloudSDKException(
        'InternalError', 'upstream PRIVATE-KEY AK-LEAK SK-LEAK', 'req-sensitive',
    ));

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    try {
        $deployer->bind(['cert' => 'LEAF', 'chain' => 'CHAIN', 'key' => 'PRIVATE-KEY'], ['secret_id' => 'AK-LEAK', 'secret_key' => 'SK-LEAK'], [
            'is_replaced' => true, 'certificate_id' => 'old', 'resource_products' => 'cdn',
        ]);
        expect(false)->toBeTrue('应抛固定本地异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('腾讯云上传更新证书任务调用失败');
        expect($e->getMessage())->not->toContain('PRIVATE-KEY')->not->toContain('AK-LEAK')->not->toContain('SK-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});

test('is_replaced=true 的详情响应畸形时固定安全错误', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->once()->andReturn(['DeployRecordDetail' => 'upstream-secret']);

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    expect(fn () => $deployer->resumePoll('9', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['is_replaced' => true]))
        ->toThrow(RuntimeException::class, '腾讯云上传更新证书任务详情响应异常');
});

test('is_replaced=true 的详情 callJson SDK 异常也丢弃上游敏感 Message', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->once()->andThrow(new TencentCloudSDKException(
        'InternalError', 'detail upstream PRIVATE-KEY AK-LEAK', 'req-detail-sensitive',
    ));

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    try {
        $deployer->resumePoll('9', ['secret_id' => 'AK-LEAK', 'secret_key' => 'SK'], ['is_replaced' => true]);
        expect(false)->toBeTrue('应抛固定本地异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('腾讯云上传更新证书任务详情调用失败');
        expect($e->getMessage())->not->toContain('PRIVATE-KEY')->not->toContain('AK-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});

test('is_replaced=true 的详情聚合拒绝 overcount 与 running 终态矛盾', function (array $record) {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->once()->andReturn(['DeployRecordDetail' => [$record]]);

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    expect(fn () => $deployer->resumePoll('9', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['is_replaced' => true]))
        ->toThrow(RuntimeException::class, '腾讯云上传更新证书任务详情响应异常');
})->with([
    'success overcount' => [['RunningTotalCount' => 0, 'SuccessTotalCount' => 2, 'FailedTotalCount' => 0, 'TotalCount' => 1]],
    'running 与完成矛盾' => [['RunningTotalCount' => 1, 'SuccessTotalCount' => 1, 'FailedTotalCount' => 0, 'TotalCount' => 1]],
    'running overcount' => [['RunningTotalCount' => 2, 'SuccessTotalCount' => 0, 'FailedTotalCount' => 0, 'TotalCount' => 1]],
]);

test('is_replaced=true 多 detail 未完成时不得误判成功并保留同一 canonical job id', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('callJson')->times(3)->andReturn([
        'DeployRecordDetail' => [
            ['RunningTotalCount' => 0, 'SuccessTotalCount' => 1, 'FailedTotalCount' => 0, 'TotalCount' => 1],
            ['RunningTotalCount' => 1, 'SuccessTotalCount' => 0, 'FailedTotalCount' => 0, 'TotalCount' => 1],
        ],
    ]);

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    try {
        $deployer->resumePoll('0009', ['secret_id' => 'AK', 'secret_key' => 'SK'], ['is_replaced' => true]);
        expect(false)->toBeTrue('应保持 pending');
    } catch (DeployPollPendingException $e) {
        expect($e->remoteJobId)->toBe('9');
    }
});

test('true 与 false 分支都把 SSL endpoint 定向传入 client factory', function (bool $isReplaced) {
    $seenCredentials = [];
    $ssl = Mockery::mock(SslClient::class);
    if ($isReplaced) {
        $ssl->shouldReceive('callJson')->twice()->andReturn(
            ['DeployStatus' => 1, 'DeployRecordId' => 9],
            sslUploadUpdateDetailResponse(success: 1),
        );
        $certRef = ['cert' => 'LEAF', 'chain' => 'CHAIN', 'key' => 'KEY'];
    } else {
        $ssl->shouldReceive('UpdateCertificateInstance')->once()->andReturn(sslUpdateResponse());
        $certRef = 'new-cert-id';
    }

    $deployer = tencentSslUpdateDeployerWith(function (string $kind, array $credentials) use ($ssl, &$seenCredentials) {
        $seenCredentials[] = $credentials;

        return $ssl;
    });
    $deployer->bind($certRef, ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'endpoint' => 'ssl.intl.tencentcloudapi.com',
        'is_replaced' => $isReplaced,
        'certificate_id' => 'old',
        'resource_products' => 'cdn',
    ]);

    expect($seenCredentials)->not->toBeEmpty();
    expect($seenCredentials[0]['endpoint'])->toBe('ssl.intl.tencentcloudapi.com');
})->with([
    'true path' => true,
    'false path' => false,
]);

test('bind 调 UpdateCertificateInstance（OldCertificateId 旧、CertificateId 新、ResourceTypes）', function () {
    $req = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UpdateCertificateInstance')->once()->andReturnUsing(function (UpdateCertificateInstanceRequest $r) use (&$req) {
        $req = $r;

        return sslUpdateResponse();
    });

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    $deployer->bind('new-cert-id', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'certificate_id' => 'old-cert-id',
        'resource_products' => ['cdn', 'live'],
    ]);

    expect($req->OldCertificateId)->toBe('old-cert-id');
    expect($req->CertificateId)->toBe('new-cert-id');
    expect($req->ResourceTypes)->toBe(['cdn', 'live']);
});

test('需要地域的产品（clb/waf）构造 ResourceTypesRegions，不需要的（cdn）不带', function () {
    $req = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UpdateCertificateInstance')->once()->andReturnUsing(function (UpdateCertificateInstanceRequest $r) use (&$req) {
        $req = $r;

        return sslUpdateResponse();
    });

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    $deployer->bind('new-cert-id', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'certificate_id' => 'old-cert-id',
        'resource_products' => 'cdn,clb,waf',
        'resource_regions' => 'ap-guangzhou,ap-shanghai',
    ]);

    // cdn 不在白名单 → 不出现在 ResourceTypesRegions；clb/waf 各带两个地域
    $regions = $req->ResourceTypesRegions;
    expect($regions)->toHaveCount(2);
    $byType = [];
    foreach ($regions as $entry) {
        $byType[$entry->ResourceType] = $entry->Regions;
    }
    expect($byType)->toHaveKey('clb')->toHaveKey('waf');
    expect($byType)->not->toHaveKey('cdn');
    expect($byType['clb'])->toBe(['ap-guangzhou', 'ap-shanghai']);
});

test('无地域时不带 ResourceTypesRegions', function () {
    $req = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UpdateCertificateInstance')->once()->andReturnUsing(function (UpdateCertificateInstanceRequest $r) use (&$req) {
        $req = $r;

        return sslUpdateResponse();
    });

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    $deployer->bind('new-cert-id', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'certificate_id' => 'old-cert-id',
        'resource_products' => 'clb',
    ]);

    expect($req->ResourceTypesRegions)->toBeNull();
});

test('一键更新首查未终态时持久化 recordId，resumePoll 续查同一记录', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UpdateCertificateInstance')->once()->andReturn(sslUpdateResponse());
    $ssl->shouldReceive('DescribeHostUpdateRecordDetail')
        ->twice()
        ->andReturnUsing(function (DescribeHostUpdateRecordDetailRequest $request) {
            expect($request->DeployRecordId)->toBe('1');
            expect($request->Limit)->toBe('200');

            static $attempt = 0;
            $attempt++;

            return $attempt === 1 ? sslUpdateDetailResponse(1) : sslUpdateDetailResponse(1, 1);
        });

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    try {
        $deployer->bind('new', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
            'certificate_id' => 'old', 'resource_products' => 'cdn',
        ]);
        expect(false)->toBeTrue('应抛 poll_pending');
    } catch (DeployPollPendingException $e) {
        expect($e->remoteJobId)->toBe('1');
    }
    $deployer->resumePoll('1', ['secret_id' => 'AK', 'secret_key' => 'SK'], []);
});

test('缺 certificate_id / resource_products 抛业务错误', function () {
    $deployer = tencentSslUpdateDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('new', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'resource_products' => 'cdn',
    ]))->toThrow(RuntimeException::class, '缺少配置 certificate_id');

    expect(fn () => $deployer->bind('new', ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'certificate_id' => 'old',
    ]))->toThrow(RuntimeException::class, '缺少配置 resource_products');
});

test('bind SDK 抛异常时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UpdateCertificateInstance')->andThrow(new TencentCloudSDKException('FailedOperation', 'update failed', 'req-1'));

    $deployer = tencentSslUpdateDeployerWith(fn () => $ssl);
    try {
        $deployer->bind('new', ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], [
            'certificate_id' => 'old', 'resource_products' => 'cdn',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('FailedOperation');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});
