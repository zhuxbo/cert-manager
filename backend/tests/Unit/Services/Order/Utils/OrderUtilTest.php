<?php

use App\Exceptions\ApiResponseException;
use App\Services\Order\Utils\OrderUtil;

// ==========================================
// getSansFromDomains
// ==========================================

test('getSansFromDomains 计算标准域名数量', function () {
    $result = OrderUtil::getSansFromDomains('example.com,test.com,api.example.com');
    expect($result)->toBe([
        'standard_count' => 3,
        'wildcard_count' => 0,
    ]);
});

test('getSansFromDomains 计算通配符域名数量', function () {
    $result = OrderUtil::getSansFromDomains('*.example.com,*.test.com');
    expect($result)->toBe([
        'standard_count' => 0,
        'wildcard_count' => 2,
    ]);
});

test('getSansFromDomains 混合域名类型', function () {
    $result = OrderUtil::getSansFromDomains('example.com,*.example.com,test.com,*.test.com');
    expect($result)->toBe([
        'standard_count' => 2,
        'wildcard_count' => 2,
    ]);
});

test('getSansFromDomains 空字符串返回全零', function () {
    $result = OrderUtil::getSansFromDomains('');
    expect($result)->toBe([
        'standard_count' => 0,
        'wildcard_count' => 0,
    ]);
});

test('getSansFromDomains 单个域名', function () {
    $result = OrderUtil::getSansFromDomains('example.com');
    expect($result)->toBe([
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);
});

test('getSansFromDomains 启用 giftRootDomain 时去除赠送域名', function () {
    $result = OrderUtil::getSansFromDomains('example.com,www.example.com', 1);
    expect($result['standard_count'])->toBe(1);
});

// ==========================================
// getEmail
// ==========================================

test('getEmail 返回匹配域名的邮箱', function () {
    $validation = [
        ['domain' => 'example.com', 'email' => 'admin@example.com', 'method' => 'EMAIL'],
        ['domain' => 'test.com', 'email' => 'admin@test.com', 'method' => 'EMAIL'],
    ];
    expect(OrderUtil::getEmail('test.com', $validation))->toBe('admin@test.com');
});

test('getEmail 域名不存在时抛出异常', function () {
    $validation = [
        ['domain' => 'example.com', 'email' => 'admin@example.com', 'method' => 'EMAIL'],
    ];
    OrderUtil::getEmail('nonexistent.com', $validation);
})->throws(ApiResponseException::class);

// ==========================================
// getMethod
// ==========================================

test('getMethod 返回匹配域名的验证方法', function () {
    $validation = [
        ['domain' => 'example.com', 'method' => 'DNS'],
        ['domain' => 'test.com', 'method' => 'HTTP'],
    ];
    expect(OrderUtil::getMethod('test.com', $validation))->toBe('HTTP');
});

test('getMethod 域名不存在时抛出异常', function () {
    $validation = [
        ['domain' => 'example.com', 'method' => 'DNS'],
    ];
    OrderUtil::getMethod('nonexistent.com', $validation);
})->throws(ApiResponseException::class);

// ==========================================
// getVerified
// ==========================================

test('getVerified 返回已验证状态', function () {
    $validation = [
        ['domain' => 'example.com', 'verified' => 1],
        ['domain' => 'test.com', 'verified' => 0],
    ];
    expect(OrderUtil::getVerified('example.com', $validation))->toBe(1)
        ->and(OrderUtil::getVerified('test.com', $validation))->toBe(0);
});

test('getVerified 域名不存在时抛出异常', function () {
    OrderUtil::getVerified('nonexistent.com', []);
})->throws(ApiResponseException::class);

test('getVerified 无 verified 字段默认返回 0', function () {
    $validation = [['domain' => 'example.com']];
    expect(OrderUtil::getVerified('example.com', $validation))->toBe(0);
});

// ==========================================
// removeVerifiedDCV
// ==========================================

test('removeVerifiedDCV 移除已验证的域名', function () {
    $validation = [
        ['domain' => 'example.com', 'verified' => 1],
        ['domain' => 'test.com', 'verified' => 0],
        ['domain' => 'other.com', 'verified' => 1],
    ];
    $result = OrderUtil::removeVerifiedDCV($validation);
    expect(array_values($result))->toHaveCount(1)
        ->and(array_values($result)[0]['domain'])->toBe('test.com');
});

test('removeVerifiedDCV 无 verified 字段不移除', function () {
    $validation = [
        ['domain' => 'example.com'],
        ['domain' => 'test.com'],
    ];
    $result = OrderUtil::removeVerifiedDCV($validation);
    expect($result)->toHaveCount(2);
});

test('removeVerifiedDCV 空数组', function () {
    expect(OrderUtil::removeVerifiedDCV([]))->toBe([]);
});

// ==========================================
// filterUnverified
// ==========================================

test('filterUnverified 仅保留未验证域名', function () {
    $newValidation = [
        ['domain' => 'example.com', 'method' => 'DNS'],
        ['domain' => 'test.com', 'method' => 'HTTP'],
    ];
    $currentValidation = [
        ['domain' => 'example.com', 'verified' => 1],
        ['domain' => 'test.com', 'verified' => 0],
    ];
    $result = OrderUtil::filterUnverified($newValidation, $currentValidation);
    expect($result)->toHaveCount(1)
        ->and($result[0]['domain'])->toBe('test.com');
});

test('filterUnverified 全部已验证返回空', function () {
    $newValidation = [['domain' => 'example.com', 'method' => 'DNS']];
    $currentValidation = [['domain' => 'example.com', 'verified' => 1]];
    $result = OrderUtil::filterUnverified($newValidation, $currentValidation);
    expect($result)->toBeEmpty();
});

// ==========================================
// convertNumericValues
// ==========================================

test('convertNumericValues 将数字字符串转换为数值', function () {
    $result = OrderUtil::convertNumericValues([
        'int' => '42',
        'float' => '3.14',
        'string' => 'hello',
        'zero' => '0',
    ]);
    expect($result)->toBe([
        'int' => 42,
        'float' => 3.14,
        'string' => 'hello',
        'zero' => 0,
    ]);
});

test('convertNumericValues 递归处理嵌套数组', function () {
    $result = OrderUtil::convertNumericValues([
        'nested' => ['value' => '100', 'name' => 'test'],
    ]);
    expect($result['nested']['value'])->toBe(100)
        ->and($result['nested']['name'])->toBe('test');
});

test('convertNumericValues 空数组', function () {
    expect(OrderUtil::convertNumericValues([]))->toBe([]);
});

test('convertNumericValues 跳过含科学计数法格式的字符串', function () {
    $result = OrderUtil::convertNumericValues([
        'registration_number' => '12220102E674422865',
        'code' => '3E8',
        'normal_int' => '42',
        'normal_float' => '3.14',
    ]);
    expect($result['registration_number'])->toBe('12220102E674422865')
        ->and($result['code'])->toBe('3E8')
        ->and($result['normal_int'])->toBe(42)
        ->and($result['normal_float'])->toBe(3.14);
});

test('convertNumericValues 保持 csr / private_key 为字符串（任意层级）', function () {
    // 这两个键的取值语义就是一段文本，转成数字会让下游 string 形参直接抛 TypeError：
    // csr='0' 曾使 POST /api/deploy 返回 HTTP 400 + 裸 TypeError 文案（含内部路径），
    // 违反「失败一律 HTTP 200 + code=0」契约。豁免后畸形取值由 CSR 解析报业务错误。
    $result = OrderUtil::convertNumericValues([
        'csr' => '0',
        'private_key' => '123456',
        'order_id' => '42',
        'params' => ['csr' => '007', 'period' => '12'],
    ]);
    expect($result['csr'])->toBe('0')
        ->and($result['private_key'])->toBe('123456')
        ->and($result['order_id'])->toBe(42)
        ->and($result['params']['csr'])->toBe('007')
        ->and($result['params']['period'])->toBe(12);
});

test('convertNumericValues 保持 registration_number 为字符串', function () {
    $result = OrderUtil::convertNumericValues([
        'organization' => [
            'registration_number' => '10444698',
        ],
        'short_number' => '10444698',
        'fifteen_digits' => '123456789012345',
        'sixteen_digits' => '1234567890123456',
    ]);
    expect($result['organization']['registration_number'])->toBe('10444698')
        ->and($result['short_number'])->toBe(10444698)
        ->and($result['fifteen_digits'])->toBe(123456789012345)
        ->and($result['sixteen_digits'])->toBe('1234567890123456');
});
