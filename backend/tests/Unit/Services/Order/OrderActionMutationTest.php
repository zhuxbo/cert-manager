<?php

use App\Models\Chain;
use App\Models\Order;
use App\Services\Notification\SystemAlert;
use App\Services\Order\Action;
use App\Services\Order\Utils\ChainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function invokeIntermediateChainGuard(Order $order, array &$data): void
{
    $method = new ReflectionMethod(Action::class, 'guardIntermediateChain');
    $method->invokeArgs(app(Action::class), [$order, &$data]);
}

function mutationProbeOrder(int $id = 123): Order
{
    $order = new Order;
    $order->setAttribute('id', $id);

    return $order;
}

test('证书链门禁任一必需字段为空时立即返回', function (array $data) {
    $verifier = Mockery::mock(ChainVerifier::class);
    $verifier->shouldNotReceive('verifyIssued');
    app()->instance(ChainVerifier::class, $verifier);

    invokeIntermediateChainGuard(mutationProbeOrder(), $data);
})->with([
    '缺 leaf' => [['cert' => '', 'intermediate_cert' => 'ca', 'issuer' => 'issuer']],
    '缺 intermediate' => [['cert' => 'leaf', 'intermediate_cert' => '', 'issuer' => 'issuer']],
    '缺 issuer' => [['cert' => 'leaf', 'intermediate_cert' => 'ca', 'issuer' => '']],
]);

test('证书链门禁已有 issuer 时不重复验签', function () {
    Chain::create([
        'common_name' => 'Existing CA',
        'intermediate_cert' => 'stored-ca',
    ]);
    $verifier = Mockery::mock(ChainVerifier::class);
    $verifier->shouldNotReceive('verifyIssued');
    app()->instance(ChainVerifier::class, $verifier);
    $data = [
        'cert' => 'leaf',
        'intermediate_cert' => 'incoming-ca',
        'issuer' => 'Existing CA',
    ];

    invokeIntermediateChainGuard(mutationProbeOrder(), $data);

    expect($data)->toBe([
        'cert' => 'leaf',
        'intermediate_cert' => 'incoming-ca',
        'issuer' => 'Existing CA',
    ]);
});

test('证书链门禁 ok 保留原数据且不告警', function () {
    $verifier = Mockery::mock(ChainVerifier::class);
    $verifier->shouldReceive('verifyIssued')
        ->once()
        ->with('leaf', 'ca', 'rsa')
        ->andReturn('ok');
    app()->instance(ChainVerifier::class, $verifier);
    $alert = Mockery::mock(SystemAlert::class);
    $alert->shouldNotReceive('send');
    app()->instance(SystemAlert::class, $alert);
    $data = [
        'cert' => 'leaf',
        'intermediate_cert' => 'ca',
        'issuer' => 'Probe CA',
        'encryption_alg' => 'rsa',
    ];

    invokeIntermediateChainGuard(mutationProbeOrder(), $data);

    expect($data)->toBe([
        'cert' => 'leaf',
        'intermediate_cert' => 'ca',
        'issuer' => 'Probe CA',
        'encryption_alg' => 'rsa',
    ]);
});

test('证书链门禁 bad 删除中间证书并精确记录日志和告警', function () {
    Log::spy();
    $verifier = Mockery::mock(ChainVerifier::class);
    $verifier->shouldReceive('verifyIssued')
        ->once()
        ->with('leaf', 'ca', '')
        ->andReturn('bad');
    $verifier->shouldReceive('lastOutput')->once()->andReturn('bad output');
    app()->instance(ChainVerifier::class, $verifier);
    $alert = Mockery::mock(SystemAlert::class);
    $alert->shouldReceive('send')->once()->with(
        'chain_verify',
        '证书链签名校验失败（坏链已拒写）',
        '订单 #321 上游返回的中间证书未签发叶证书，已拒绝写入 chains，订单将转 approving 等待重新同步。',
        ['order_id' => 321, 'issuer' => 'Bad CA', 'reason' => 'chain_verify_failed'],
        'chain_bad:Bad CA',
        24,
        'bad'
    )->andReturnTrue();
    app()->instance(SystemAlert::class, $alert);
    $data = [
        'cert' => 'leaf',
        'intermediate_cert' => 'ca',
        'issuer' => 'Bad CA',
    ];

    invokeIntermediateChainGuard(mutationProbeOrder(321), $data);

    expect($data)->toBe(['cert' => 'leaf', 'issuer' => 'Bad CA']);
    Log::shouldHaveReceived('error')->once()->with(
        '证书链签名校验失败：中间证书未签发叶证书，拒写 chains',
        ['order_id' => 321, 'issuer' => 'Bad CA', 'openssl_output' => 'bad output']
    );
});

test('证书链门禁 unverifiable 放行数据并精确记录日志和告警', function () {
    Log::spy();
    $verifier = Mockery::mock(ChainVerifier::class);
    $verifier->shouldReceive('verifyIssued')
        ->once()
        ->with('leaf', 'ca', 'sm2')
        ->andReturn('unverifiable');
    $verifier->shouldReceive('lastOutput')->once()->andReturn('missing openssl');
    app()->instance(ChainVerifier::class, $verifier);
    $alert = Mockery::mock(SystemAlert::class);
    $alert->shouldReceive('send')->once()->with(
        'chain_verify',
        '证书链签名校验无法执行（已放行写链）',
        '订单 #654 的证书链签名校验无法执行（openssl 不可用或输出异常），已按 fail-open 放行写入 chains。'
            .'故障期间新写入的证书链建议人工复核（Admin 链管理）。',
        ['order_id' => 654, 'issuer' => 'Unknown CA', 'reason' => 'openssl_unavailable'],
        'chain_unverifiable',
        24,
        'unavailable'
    )->andReturnTrue();
    app()->instance(SystemAlert::class, $alert);
    $data = [
        'cert' => 'leaf',
        'intermediate_cert' => 'ca',
        'issuer' => 'Unknown CA',
        'encryption_alg' => 'sm2',
    ];

    invokeIntermediateChainGuard(mutationProbeOrder(654), $data);

    expect($data)->toBe([
        'cert' => 'leaf',
        'intermediate_cert' => 'ca',
        'issuer' => 'Unknown CA',
        'encryption_alg' => 'sm2',
    ]);
    Log::shouldHaveReceived('error')->once()->with(
        '证书链签名校验无法执行（openssl 不可用或输出不可解析），已 fail-open 放行写链',
        [
            'order_id' => 654,
            'issuer' => 'Unknown CA',
            'openssl_output' => 'missing openssl',
        ]
    );
});
