<?php

use App\Exceptions\ApiResponseException;
use App\Http\Controllers\V2\ApiController;
use App\Models\Order;
use App\Models\Product;
use App\Services\Order\Action;
use Illuminate\Http\Request;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

// ── 辅助函数 ──

function setProperty(ApiController $controller, string $property, mixed $value): void
{
    $reflection = new ReflectionClass($controller);
    $prop = $reflection->getProperty($property);
    $prop->setValue($controller, $value);
}

function makeController(array $input, string $method, Action $action, int $userId): ApiController
{
    $request = Request::create('/api/v2/test', $method, $input);

    $reflection = new ReflectionClass(ApiController::class);
    $controller = $reflection->newInstanceWithoutConstructor();

    setProperty($controller, 'request', $request);
    setProperty($controller, 'user_id', $userId);
    setProperty($controller, 'model', new Order);
    setProperty($controller, 'action', $action);

    return $controller;
}

function callPrivate(ApiController $controller, string $method, array $args): mixed
{
    $reflection = new ReflectionClass($controller);
    $m = $reflection->getMethod($method);

    return $m->invokeArgs($controller, $args);
}

function captureResponse(callable $callback): array
{
    try {
        $callback();
        test()->fail('Expected ApiResponseException but none was thrown.');
    } catch (ApiResponseException $e) {
        return $e->getApiResponse();
    }
}

// ── cleanDcvAndValidation 白名单测试 ──

test('cleanDcvAndValidation 剥离 delegation 内部字段', function () {
    $controller = makeController([], 'GET', Mockery::mock(Action::class), 1);

    $dcv = [
        'method' => 'txt',
        'is_delegate' => true,
        'ca' => 'sectigo',
        'dns' => ['host' => '_hash', 'type' => 'CNAME', 'value' => 'xxx.sectigo.com'],
    ];
    $validation = [
        [
            'domain' => 'example.com',
            'method' => 'txt',
            'is_delegate' => true,
            'delegation_id' => 123,
            'delegation_target' => 'xxx.delegate.example.com',
            'delegation_valid' => true,
            'delegation_zone' => 'example.com',
            'auto_txt_written' => true,
            'host' => '_hash',
            'value' => 'xxx.sectigo.com',
        ],
    ];

    $result = callPrivate($controller, 'cleanDcvAndValidation', [$dcv, $validation]);

    // dcv 只保留 method/dns/file
    expect($result['dcv'])->toBe([
        'method' => 'txt',
        'dns' => ['host' => '_hash', 'type' => 'CNAME', 'value' => 'xxx.sectigo.com'],
    ]);
    expect($result['dcv'])->not->toHaveKeys(['is_delegate', 'ca']);

    // validation 只保留白名单字段
    $item = $result['validation'][0];
    expect($item)->toHaveKeys(['domain', 'method', 'host', 'value']);
    expect($item)->not->toHaveKeys([
        'is_delegate', 'delegation_id', 'delegation_target',
        'delegation_valid', 'delegation_zone', 'auto_txt_written',
    ]);
});

test('cleanDcvAndValidation 保留文件验证字段', function () {
    $controller = makeController([], 'GET', Mockery::mock(Action::class), 1);

    $dcv = [
        'method' => 'http',
        'file' => ['name' => 'ABC.txt', 'path' => '/.well-known/pki-validation/ABC.txt', 'content' => 'hash-content'],
    ];
    $validation = [
        [
            'domain' => 'example.com',
            'method' => 'http',
            'name' => 'ABC.txt',
            'content' => 'hash-content',
            'link' => 'http://example.com/.well-known/pki-validation/ABC.txt',
        ],
    ];

    $result = callPrivate($controller, 'cleanDcvAndValidation', [$dcv, $validation]);

    expect($result['dcv'])->toBe([
        'method' => 'http',
        'file' => ['name' => 'ABC.txt', 'path' => '/.well-known/pki-validation/ABC.txt', 'content' => 'hash-content'],
    ]);

    $item = $result['validation'][0];
    expect($item)->toBe([
        'domain' => 'example.com',
        'method' => 'http',
        'name' => 'ABC.txt',
        'content' => 'hash-content',
        'link' => 'http://example.com/.well-known/pki-validation/ABC.txt',
    ]);
});

test('cleanDcvAndValidation 保留 Certum 验证错误和过期时间字段', function () {
    $controller = makeController([], 'GET', Mockery::mock(Action::class), 1);

    $dcv = ['method' => 'txt', 'dns' => ['host' => '_certum', 'type' => 'TXT', 'value' => 'code123']];
    $validation = [
        [
            'domain' => 'example.com',
            'method' => 'txt',
            'verified' => 2,
            'host' => '_certum',
            'value' => 'code123',
            'error' => ['system' => 'DNS_TXT_PREFIX', 'info' => 'TXT record not found'],
            'expires_date' => 1742169600,
        ],
    ];

    $result = callPrivate($controller, 'cleanDcvAndValidation', [$dcv, $validation]);

    $item = $result['validation'][0];
    expect($item)->toHaveKeys(['error', 'expires_date']);
    expect($item['error'])->toBe(['system' => 'DNS_TXT_PREFIX', 'info' => 'TXT record not found']);
    expect($item['expires_date'])->toBe(1742169600);
});

test('cleanDcvAndValidation 处理 null 输入', function () {
    $controller = makeController([], 'GET', Mockery::mock(Action::class), 1);

    $result = callPrivate($controller, 'cleanDcvAndValidation', [null, null]);

    expect($result)->toBe(['dcv' => null, 'validation' => null]);
});

test('cleanDcvAndValidation 保留邮件验证字段', function () {
    $controller = makeController([], 'GET', Mockery::mock(Action::class), 1);

    $dcv = ['method' => 'admin'];
    $validation = [
        ['domain' => 'example.com', 'method' => 'admin', 'email' => 'admin@example.com'],
    ];

    $result = callPrivate($controller, 'cleanDcvAndValidation', [$dcv, $validation]);

    expect($result['validation'][0])->toBe([
        'domain' => 'example.com',
        'method' => 'admin',
        'email' => 'admin@example.com',
    ]);
});

test('cleanDcvAndValidation 过滤未来可能新增的内部字段', function () {
    $controller = makeController([], 'GET', Mockery::mock(Action::class), 1);

    $dcv = [
        'method' => 'txt',
        'dns' => ['host' => '_hash', 'value' => 'token'],
        'some_internal_flag' => true,
        'another_secret' => 'data',
    ];
    $validation = [
        [
            'domain' => 'example.com',
            'method' => 'txt',
            'host' => '_hash',
            'value' => 'token',
            'unknown_internal' => 'should_not_leak',
        ],
    ];

    $result = callPrivate($controller, 'cleanDcvAndValidation', [$dcv, $validation]);

    expect($result['dcv'])->not->toHaveKeys(['some_internal_flag', 'another_secret']);
    expect($result['validation'][0])->not->toHaveKey('unknown_internal');
});

// ── delegation 拦截测试 ──

test('new 拦截 delegation 验证方法', function () {
    $controller = makeController([
        'validation_method' => 'delegation',
        'product_code' => 'test',
    ], 'POST', Mockery::mock(Action::class), 1);

    $response = captureResponse(fn () => $controller->new());

    expect($response['code'])->toBe(0);
    expect($response['msg'])->toContain('委托验证');
});

test('renew 拦截 delegation 验证方法', function () {
    $controller = makeController([
        'validation_method' => 'delegation',
        'order_id' => '999',
    ], 'POST', Mockery::mock(Action::class), 1);

    $response = captureResponse(fn () => $controller->renew());

    expect($response['code'])->toBe(0);
    expect($response['msg'])->toContain('委托验证');
});

test('reissue 拦截 delegation 验证方法', function () {
    $controller = makeController([
        'validation_method' => 'delegation',
        'order_id' => '999',
    ], 'POST', Mockery::mock(Action::class), 1);

    $response = captureResponse(fn () => $controller->reissue());

    expect($response['code'])->toBe(0);
    expect($response['msg'])->toContain('委托验证');
});

test('updateDCV 拦截 delegation 验证方法', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['status' => 'processing']);

    $controller = makeController([
        'order_id' => $order->id,
        'method' => 'delegation',
    ], 'POST', Mockery::mock(Action::class), $user->id);

    $response = captureResponse(fn () => $controller->updateDCV());

    expect($response['code'])->toBe(0);
    expect($response['msg'])->toContain('委托验证');
});

// ── getProducts delegation 过滤测试 ──

test('getProducts 过滤 validation_methods 中的 delegation', function () {
    $user = $this->createTestUser();

    Product::factory()->create([
        'status' => 1,
        'product_type' => 'ssl',
        'validation_methods' => ['txt', 'delegation', 'cname', 'http'],
        'periods' => [12],
    ]);

    $controller = makeController([], 'GET', Mockery::mock(Action::class), $user->id);

    $response = captureResponse(fn () => $controller->getProducts());

    // getProducts 通过 success() 抛出 ApiResponseException
    expect($response['code'])->toBe(1);

    // 检查返回的产品中不包含 delegation
    if (! empty($response['data'])) {
        foreach ($response['data'] as $product) {
            if (isset($product['validation_methods'])) {
                expect($product['validation_methods'])->not->toContain('delegation');
            }
        }
    }
});

// ── get 返回时 dcv/validation 被清理 ──

test('get 返回的 dcv/validation 不包含 delegation 内部字段', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, [
        'status' => 'processing',
        'dcv' => [
            'method' => 'txt',
            'is_delegate' => true,
            'ca' => 'sectigo',
            'dns' => ['host' => '_hash', 'value' => 'token'],
        ],
        'validation' => [
            [
                'domain' => 'example.com',
                'method' => 'txt',
                'is_delegate' => true,
                'delegation_id' => 1,
                'delegation_target' => 'xxx.delegate.example.com',
                'delegation_valid' => true,
                'delegation_zone' => 'example.com',
                'host' => '_hash',
                'value' => 'token',
            ],
        ],
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('sync')->once();

    $controller = makeController([
        'order_id' => $order->id,
    ], 'GET', $mockAction, $user->id);

    $response = captureResponse(fn () => $controller->get());

    expect($response['code'])->toBe(1);

    $data = $response['data'];

    // dcv 不含内部字段
    expect($data['dcv'])->toHaveKeys(['method', 'dns']);
    expect($data['dcv'])->not->toHaveKeys(['is_delegate', 'ca']);

    // validation 不含 delegation 字段
    $item = $data['validation'][0];
    expect($item)->toHaveKeys(['domain', 'method', 'host', 'value']);
    expect($item)->not->toHaveKeys([
        'is_delegate', 'delegation_id', 'delegation_target',
        'delegation_valid', 'delegation_zone',
    ]);
});

// ── get 国密(SM2)enc 字段透传(多级 manager 链路契约) ──

test('V2 get 国密订单透传 enc 字段，键名为 certs 列名供下游 sync fillable 写入', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, [
        'common_name' => 'sm2.example.com',
        'status' => 'active',
        'encryption_alg' => 'SM2',
        'cert' => "-----BEGIN CERTIFICATE-----\nSIGN\n-----END CERTIFICATE-----",
        'enc_cert' => "-----BEGIN CERTIFICATE-----\nENC\n-----END CERTIFICATE-----",
        'enc_key' => 'ENC-KEY-GMT0016',
        'enc_key2' => 'ENC-KEY-GMT0009',
    ]);

    $action = Mockery::mock(Action::class);
    $action->shouldReceive('sync')->andReturnNull();

    $controller = makeController(['order_id' => (string) $order->id], 'GET', $action, $user->id);
    $response = captureResponse(fn () => $controller->get());

    expect($response['code'])->toBe(1);
    $data = $response['data'];
    // 键名必须是 certs 列名(enc_cert/enc_key/enc_key2)，下游 manager 经 default source 拉取后 sync 直接 fillable 写入
    expect($data['enc_cert'])->toBe("-----BEGIN CERTIFICATE-----\nENC\n-----END CERTIFICATE-----");
    expect($data['enc_key'])->toBe('ENC-KEY-GMT0016');
    expect($data['enc_key2'])->toBe('ENC-KEY-GMT0009');
});

test('V2 get 非国密订单不返回 enc 字段(空值清理，与 private_key 同策略)', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, [
        'common_name' => 'rsa.example.com',
        'status' => 'active',
        'encryption_alg' => 'RSA',
        'cert' => "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----",
        // enc_cert / enc_key / enc_key2 全空(非国密)
    ]);

    $action = Mockery::mock(Action::class);
    $action->shouldReceive('sync')->andReturnNull();

    $controller = makeController(['order_id' => (string) $order->id], 'GET', $action, $user->id);
    $response = captureResponse(fn () => $controller->get());

    expect($response['code'])->toBe(1);
    expect($response['data'])->not->toHaveKeys(['enc_cert', 'enc_key', 'enc_key2']);
});
