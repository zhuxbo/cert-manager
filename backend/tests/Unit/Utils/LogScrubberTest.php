<?php

use App\Utils\LogScrubber;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    config([
        'logs.scrubber.extra_fields' => [],
        'logs.scrubber.extra_patterns' => [],
        'logs.scrubber.extra_nested_fields' => [],
    ]);
});

test('内置敏感字段精确匹配脱敏', function () {
    $result = LogScrubber::scrub([
        'password' => 'secret123',
        'username' => 'admin',
    ]);

    expect($result['password'])->toBe('******');
    expect($result['username'])->toBe('admin');
});

test('大小写不敏感匹配', function () {
    $result = LogScrubber::scrub([
        'Password' => 'a',
        'PASSWORD' => 'b',
        'pAsSwOrD' => 'c',
    ]);

    expect($result['Password'])->toBe('******');
    expect($result['PASSWORD'])->toBe('******');
    expect($result['pAsSwOrD'])->toBe('******');
});

test('正则模式匹配（包含 token / secret / key / auth / password）', function () {
    $result = LogScrubber::scrub([
        'my_token' => 'a',
        'auth_secret' => 'b',
        'api_key_v2' => 'c',
        'foo_authorization' => 'd',
        'user_password_v1' => 'e',
        'normal_field' => 'keep',
    ]);

    expect($result['my_token'])->toBe('******');
    expect($result['auth_secret'])->toBe('******');
    expect($result['api_key_v2'])->toBe('******');
    expect($result['foo_authorization'])->toBe('******');
    expect($result['user_password_v1'])->toBe('******');
    expect($result['normal_field'])->toBe('keep');
});

test('嵌套数组递归脱敏', function () {
    $result = LogScrubber::scrub([
        'level1' => [
            'level2' => [
                'password' => 'pwd',
                'name' => 'tom',
            ],
            'token' => 'tk',
        ],
    ]);

    expect($result['level1']['level2']['password'])->toBe('******');
    expect($result['level1']['level2']['name'])->toBe('tom');
    expect($result['level1']['token'])->toBe('******');
});

test('JSON 字符串响应解码后递归脱敏', function () {
    $json = json_encode([
        'code' => 1,
        'data' => [
            'access_token' => 'jwt-value',
            'username' => 'admin',
        ],
    ]);

    $result = LogScrubber::scrubResponse($json);

    expect($result['data']['access_token'])->toBe('******');
    expect($result['data']['username'])->toBe('admin');
});

test('空值响应返回 null', function () {
    expect(LogScrubber::scrubResponse(''))->toBeNull();
    expect(LogScrubber::scrubResponse(null))->toBeNull();
    expect(LogScrubber::scrubResponse([]))->toBeNull();
    expect(LogScrubber::scrubResponse(0))->toBeNull();
    expect(LogScrubber::scrubResponse(false))->toBeNull();
});

test('非数组非 JSON 响应包装为 content 字段', function () {
    $result = LogScrubber::scrubResponse('plain text response');

    expect($result)->toBeArray();
    expect($result['content'])->toBe('plain text response');
});

test('config extra_fields 扩展生效', function () {
    config(['logs.scrubber.extra_fields' => ['internal_secret_field', 'custom_pin']]);

    $result = LogScrubber::scrub([
        'internal_secret_field' => 'topsecret',
        'custom_pin' => '1234',
        'normal' => 'keep',
    ]);

    expect($result['internal_secret_field'])->toBe('******');
    expect($result['custom_pin'])->toBe('******');
    expect($result['normal'])->toBe('keep');
});

test('config extra_patterns 扩展生效', function () {
    config(['logs.scrubber.extra_patterns' => ['/.*credit.*/i']]);

    $result = LogScrubber::scrub([
        'credit_card_no' => '1234-5678-9012-3456',
        'CreditScore' => 720,
        'order_id' => 100,
    ]);

    expect($result['credit_card_no'])->toBe('******');
    expect($result['CreditScore'])->toBe('******');
    expect($result['order_id'])->toBe(100);
});

test('空字符串字段不被替换为星号', function () {
    $result = LogScrubber::scrub([
        'password' => '',
        'token' => null,
        'secret' => 0,
        'auth' => false,
    ]);

    expect($result['password'])->toBe('');
    expect($result['token'])->toBeNull();
    expect($result['secret'])->toBe(0);
    expect($result['auth'])->toBeFalse();
});

test('isSensitive 公共方法可外部判断字段名', function () {
    expect(LogScrubber::isSensitive('password'))->toBeTrue();
    expect(LogScrubber::isSensitive('TOKEN'))->toBeTrue();
    expect(LogScrubber::isSensitive('my_api_key'))->toBeTrue();
    expect(LogScrubber::isSensitive('username'))->toBeFalse();
    expect(LogScrubber::isSensitive('domain'))->toBeFalse();
});

test('数组形式响应直接递归脱敏（无 JSON 解码）', function () {
    $result = LogScrubber::scrubResponse([
        'code' => 1,
        'data' => [
            'token' => 'tk',
            'name' => 'a',
        ],
    ]);

    expect($result['data']['token'])->toBe('******');
    expect($result['data']['name'])->toBe('a');
});

test('内置覆盖业务敏感字段（bank_account / bank_card / id_card / national_id）', function () {
    $result = LogScrubber::scrub([
        'bank_account' => '6228480402564890018',
        'bank_card' => '6225 8800 1234 5678',
        'id_card' => '110101199003078888',
        'national_id' => 'A12345678',
        'username' => 'tom',
    ]);

    expect($result['bank_account'])->toBe('******');
    expect($result['bank_card'])->toBe('******');
    expect($result['id_card'])->toBe('******');
    expect($result['national_id'])->toBe('******');
    expect($result['username'])->toBe('tom');
});

test('内置覆盖 cert/private/credit 类字段', function () {
    $result = LogScrubber::scrub([
        'cert_pem' => "-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----",
        'private' => 'raw private blob',
        'credit' => '6225-8800-...',
        'credit_card' => '6225 8800 1234 5678',
        'credit_score' => 720,
        'leaf_pem' => "-----BEGIN CERTIFICATE-----\nMIIC...\n-----END CERTIFICATE-----",
        'CreditScore' => 800,
        'username' => 'tom',
    ]);

    expect($result['cert_pem'])->toBe('******');
    expect($result['private'])->toBe('******');
    expect($result['credit'])->toBe('******');
    expect($result['credit_card'])->toBe('******');
    expect($result['credit_score'])->toBe('******');
    expect($result['leaf_pem'])->toBe('******');
    expect($result['CreditScore'])->toBe('******');
    expect($result['username'])->toBe('tom');
});

test('数字索引数组中嵌套敏感字段也会脱敏', function () {
    $result = LogScrubber::scrub([
        'list' => [
            ['password' => 'p1', 'name' => 'a'],
            ['password' => 'p2', 'name' => 'b'],
        ],
    ]);

    expect($result['list'][0]['password'])->toBe('******');
    expect($result['list'][1]['password'])->toBe('******');
    expect($result['list'][0]['name'])->toBe('a');
});

// ==========================================
// scrubWith：覆盖字段集（直对接异构 CA SDK 预留扩展）
// ==========================================

test('scrubWith 仅使用传入字段集，不叠加内置默认', function () {
    $result = LogScrubber::scrubWith([
        'csr_code' => 'csr-content',
        'crt_code' => 'crt-content',
        'ca_code' => 'ca-content',
        'password' => 'should-not-be-scrubbed',
        'normal' => 'keep',
    ], ['csr_code', 'crt_code', 'ca_code']);

    expect($result['csr_code'])->toBe('******');
    expect($result['crt_code'])->toBe('******');
    expect($result['ca_code'])->toBe('******');
    // password 不在传入字段集中，且 scrubWith 不读内置字段，所以保留原值
    expect($result['password'])->toBe('should-not-be-scrubbed');
    expect($result['normal'])->toBe('keep');
});

test('scrubWith 支持自定义正则', function () {
    $result = LogScrubber::scrubWith([
        'cert_a' => 'a',
        'cert_b' => 'b',
        'normal' => 'keep',
    ], [], ['/^cert_.*/i']);

    expect($result['cert_a'])->toBe('******');
    expect($result['cert_b'])->toBe('******');
    expect($result['normal'])->toBe('keep');
});

test('scrubWith 空响应返回 null', function () {
    expect(LogScrubber::scrubWith(null, ['x']))->toBeNull();
    expect(LogScrubber::scrubWith('', ['x']))->toBeNull();
    expect(LogScrubber::scrubWith([], ['x']))->toBeNull();
});

test('scrubWith 解码 JSON 字符串', function () {
    $json = json_encode(['X509Cert' => 'pem-blob', 'orderID' => 'abc']);
    $result = LogScrubber::scrubWith($json, ['X509Cert']);

    expect($result['X509Cert'])->toBe('******');
    expect($result['orderID'])->toBe('abc');
});

test('scrubWith 嵌套递归', function () {
    $result = LogScrubber::scrubWith([
        'data' => [
            'csr' => 'csr-blob',
            'name' => 'tom',
        ],
    ], ['csr']);

    expect($result['data']['csr'])->toBe('******');
    expect($result['data']['name'])->toBe('tom');
});

test('scrubWith 大小写不敏感', function () {
    $result = LogScrubber::scrubWith([
        'X509Cert' => 'a',
        'x509cert' => 'b',
    ], ['x509cert']);

    expect($result['X509Cert'])->toBe('******');
    expect($result['x509cert'])->toBe('******');
});

// ==========================================
// SENSITIVE_NESTED_FIELDS：嵌套路径敏感（部分 CA 响应字段名通用，需路径感知）
// ==========================================

test('内置嵌套字段 certificate.path 脱敏', function () {
    $result = LogScrubber::scrub([
        'certificate' => [
            'path' => 'pem-blob',
            'serialNumber' => 'keep',
        ],
    ]);

    expect($result['certificate']['path'])->toBe('******');
    expect($result['certificate']['serialNumber'])->toBe('keep');
});

test('顶层 path 字段不脱敏（路径不匹配）', function () {
    $result = LogScrubber::scrub([
        'path' => '/api/v1/orders',
    ]);

    // path 不在内置敏感字段，单独出现不脱敏
    expect($result['path'])->toBe('/api/v1/orders');
});

test('extra_nested_fields 配置扩展生效', function () {
    config(['logs.scrubber.extra_nested_fields' => ['order.secret_field']]);

    $result = LogScrubber::scrub([
        'order' => ['secret_field' => 'top-secret', 'normal' => 'keep'],
    ]);

    expect($result['order']['secret_field'])->toBe('******');
    expect($result['order']['normal'])->toBe('keep');
});

test('credentials.hmac 内置敏感（hmac 字段名直接匹配）', function () {
    $result = LogScrubber::scrub([
        'credentials' => ['hmac' => 'eab-secret', 'kid' => 'keep'],
    ]);

    expect($result['credentials']['hmac'])->toBe('******');
    expect($result['credentials']['kid'])->toBe('keep');
});

// ========================================
// scrubUrl —— URL 查询串脱敏（F1-3）
// ========================================

test('scrubUrl 脱敏 token 查询参数', function () {
    expect(LogScrubber::scrubUrl('https://a.com/api/deploy?token=abc123'))
        ->toContain('token=%2A%2A%2A%2A%2A%2A') // http_build_query 会 urlencode ******
        ->not->toContain('abc123');
});

test('scrubUrl 仅脱敏敏感 key，保留 order/field 等业务参数', function () {
    $result = LogScrubber::scrubUrl('https://a.com/api/deploy?order=5&token=abc123&field=certificate');

    expect($result)
        ->toContain('order=5')
        ->toContain('field=certificate')
        ->not->toContain('abc123');
});

test('scrubUrl 无 query 原样返回', function () {
    expect(LogScrubber::scrubUrl('https://a.com/api/deploy'))
        ->toBe('https://a.com/api/deploy');
});

test('scrubUrl 畸形 URL 不抛异常（兜底原样返回）', function () {
    $bad = 'http://:::not-a-url:::';
    expect(fn () => LogScrubber::scrubUrl($bad))->not->toThrow(Throwable::class);
    // 解析失败或无 query → 原样返回（不脱敏也不崩）
    expect(LogScrubber::scrubUrl($bad))->toBeString();
});

test('scrubUrl 命中 access_token / secret / 大小写变体', function () {
    $result = LogScrubber::scrubUrl('https://a.com/x?access_token=aaa&SECRET=bbb&keep=ccc');

    expect($result)
        ->not->toContain('aaa')
        ->not->toContain('bbb')
        ->toContain('keep=ccc');
});

test('scrubUrl 数组型查询参数不崩', function () {
    expect(fn () => LogScrubber::scrubUrl('https://a.com/x?a[]=1&a[]=2&token=zzz'))
        ->not->toThrow(Throwable::class);

    expect(LogScrubber::scrubUrl('https://a.com/x?a[]=1&a[]=2&token=zzz'))
        ->not->toContain('zzz');
});

test('scrubUrl 保留 path 与 fragment', function () {
    $result = LogScrubber::scrubUrl('https://a.com/api/deploy/sub?token=x#frag');

    expect($result)
        ->toContain('/api/deploy/sub')
        ->toContain('#frag')
        ->not->toContain('token=x');
});
