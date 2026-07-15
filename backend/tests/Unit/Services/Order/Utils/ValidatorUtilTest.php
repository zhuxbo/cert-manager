<?php

use App\Services\Order\Utils\ValidatorUtil;
use Tests\TestCase;

uses(TestCase::class);

// ==========================================
// validateDomain
// ==========================================

test('validateDomain 标准域名在允许类型中通过', function () {
    $result = ValidatorUtil::validateDomain('example.com', ['standard', 'wildcard']);
    expect($result)->toBe('');
});

test('validateDomain 通配符域名在允许类型中通过', function () {
    $result = ValidatorUtil::validateDomain('*.example.com', ['standard', 'wildcard']);
    expect($result)->toBe('');
});

test('validateDomain 域名类型不在允许列表中返回错误', function () {
    $result = ValidatorUtil::validateDomain('*.example.com', ['standard']);
    expect($result)->toContain('类型错误');
});

test('validateDomain IP 地址类型不在允许列表中返回错误', function () {
    $result = ValidatorUtil::validateDomain('1.2.3.4', ['standard', 'wildcard']);
    expect($result)->toContain('类型错误');
});

test('validateDomain IP 地址在允许类型中通过', function () {
    $result = ValidatorUtil::validateDomain('1.2.3.4', ['standard', 'wildcard', 'ipv4']);
    expect($result)->toBe('');
});

// ==========================================
// validatePeriod
// ==========================================

test('validatePeriod 有效期在产品允许列表中通过', function () {
    $product = ['periods' => [365, 730]];
    $result = ValidatorUtil::validatePeriod(365, $product);
    expect($result)->toBe('');
});

test('validatePeriod 有效期不在产品允许列表中返回错误', function () {
    $product = ['periods' => [365, 730]];
    $result = ValidatorUtil::validatePeriod(180, $product);
    expect($result)->toContain('有效期只能使用');
});

test('validatePeriod 空产品返回错误', function () {
    $result = ValidatorUtil::validatePeriod(365, []);
    expect($result)->toBe('未找到产品');
});

test('validatePeriod 字符串有效期在允许列表中通过', function () {
    $product = ['periods' => ['365', '730']];
    $result = ValidatorUtil::validatePeriod('365', $product);
    expect($result)->toBe('');
});

test('validatePeriod periods 是逗号分隔字符串时正常解析', function () {
    $product = ['periods' => '365,730'];
    $result = ValidatorUtil::validatePeriod(365, $product);
    expect($result)->toBe('');
});

test('validatePeriod 非整数非字符串返回错误', function () {
    $product = ['periods' => [365]];
    $result = ValidatorUtil::validatePeriod([], $product);
    expect($result)->toBe('有效期必须是整数或字符串');
});

// ==========================================
// validateValidationMethod
// ==========================================

test('validateValidationMethod 验证方法在允许列表中通过', function () {
    $product = ['validation_methods' => ['dns', 'http', 'email']];
    $result = ValidatorUtil::validateValidationMethod('dns', $product);
    expect($result)->toBe('');
});

test('validateValidationMethod 验证方法不在允许列表中返回错误', function () {
    $product = ['validation_methods' => ['dns', 'email']];
    $result = ValidatorUtil::validateValidationMethod('http', $product);
    expect($result)->toContain('验证方法只能使用');
});

test('validateValidationMethod 空产品返回错误', function () {
    $result = ValidatorUtil::validateValidationMethod('dns', []);
    expect($result)->toBe('未找到产品');
});

test('validateValidationMethod 非字符串返回错误', function () {
    $product = ['validation_methods' => ['dns']];
    $result = ValidatorUtil::validateValidationMethod(123, $product);
    expect($result)->toBe('验证方法必须是字符串');
});

test('validateValidationMethod validation_methods 是逗号分隔字符串时正常解析', function () {
    $product = ['validation_methods' => 'dns,http,email'];
    $result = ValidatorUtil::validateValidationMethod('http', $product);
    expect($result)->toBe('');
});

// ==========================================
// validateEncryption
// ==========================================

test('validateEncryption 合法加密算法通过', function () {
    $product = [
        'encryption_alg' => ['rsa', 'ecdsa'],
        'signature_digest_alg' => ['sha256', 'sha384'],
    ];
    $result = ValidatorUtil::validateEncryption(['alg' => 'rsa', 'digest_alg' => 'sha256'], $product);
    expect($result)->toBeEmpty();
});

test('validateEncryption 非法加密算法返回错误', function () {
    $product = [
        'encryption_alg' => ['rsa'],
        'signature_digest_alg' => ['sha256'],
    ];
    $result = ValidatorUtil::validateEncryption(['alg' => 'ecdsa'], $product);
    expect($result)->not->toBeEmpty();
});

test('validateEncryption 非法摘要算法返回错误', function () {
    $product = [
        'encryption_alg' => ['rsa'],
        'signature_digest_alg' => ['sha256'],
    ];
    $result = ValidatorUtil::validateEncryption(['digest_alg' => 'sha512'], $product);
    expect($result)->not->toBeEmpty();
});

test('validateEncryption 空产品返回错误', function () {
    $result = ValidatorUtil::validateEncryption(['alg' => 'rsa'], []);
    expect($result)->toBe('未找到产品');
});

test('validateEncryption 大写算法自动转小写匹配', function () {
    $product = [
        'encryption_alg' => ['rsa'],
        'signature_digest_alg' => ['sha256'],
    ];
    $result = ValidatorUtil::validateEncryption(['alg' => 'RSA', 'digest_alg' => 'SHA256'], $product);
    expect($result)->toBeEmpty();
});

// ==========================================
// validateContact
// ==========================================

test('validateContact 完整合法数据通过', function () {

    $contact = [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'title' => 'Manager',
        'email' => 'john@test.com',
        'phone' => '13800138000',
    ];
    $result = ValidatorUtil::validateContact($contact);
    expect($result)->toBeEmpty();
});

test('validateContact 文本字段拒绝数组值', function () {
    $contact = [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'title' => 'Manager',
        'email' => 'john@test.com',
        'phone' => '13800138000',
    ];

    $arraySizes = [
        'first_name' => 1,
        'last_name' => 1,
        'title' => 2,
        'email' => 6,
        'phone' => 5,
    ];

    foreach ($arraySizes as $field => $size) {
        $invalid = array_replace($contact, [$field => array_fill(0, $size, 'unexpected')]);
        expect(ValidatorUtil::validateContact($invalid))->toHaveKey($field);
    }
});

test('validateContact 接受数值归一化后的电话', function () {
    $contact = [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'title' => 'Manager',
        'email' => 'john@test.com',
        'phone' => 13800138000,
    ];

    expect(ValidatorUtil::validateContact($contact))->toBeEmpty();
});

test('validateContact 缺少必填字段返回错误', function () {

    $contact = ['first_name' => 'John'];
    $result = ValidatorUtil::validateContact($contact);
    expect($result)->toHaveKey('last_name')
        ->toHaveKey('title')
        ->toHaveKey('email')
        ->toHaveKey('phone');
});

test('validateContact 邮箱格式错误', function () {

    $contact = [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'title' => 'Manager',
        'email' => 'invalid-email',
        'phone' => '13800138000',
    ];
    $result = ValidatorUtil::validateContact($contact);
    expect($result)->toHaveKey('email');
});

// ==========================================
// validateOrganization
// ==========================================

test('validateOrganization 完整合法数据通过', function () {

    $org = [
        'name' => 'ACME Corp',
        'registration_number' => '123456789012345678',
        'phone' => '13800138000',
        'address' => '123 Main Street',
        'city' => 'Shanghai',
        'state' => 'Shanghai',
        'country' => 'CN',
        'postcode' => '200000',
    ];
    $result = ValidatorUtil::validateOrganization($org);
    expect($result)->toBeEmpty();
});

test('validateOrganization 文本字段拒绝数组值', function () {
    $organization = [
        'name' => 'ACME Corp',
        'registration_number' => '123456789012345678',
        'phone' => '13800138000',
        'address' => '123 Main Street',
        'city' => 'Shanghai',
        'state' => 'Shanghai',
        'country' => 'CN',
        'postcode' => '200000',
    ];

    $arraySizes = [
        'name' => 2,
        'registration_number' => 6,
        'phone' => 5,
        'address' => 2,
        'city' => 2,
        'state' => 2,
        'country' => 2,
        'postcode' => 4,
    ];

    foreach ($arraySizes as $field => $size) {
        $invalid = array_replace($organization, [$field => array_fill(0, $size, 'unexpected')]);
        expect(ValidatorUtil::validateOrganization($invalid))->toHaveKey($field);
    }
});

test('validateOrganization 接受数值归一化后的电话与邮编', function () {
    $organization = [
        'name' => 'ACME Corp',
        'registration_number' => '123456789012345678',
        'phone' => 13800138000,
        'address' => '123 Main Street',
        'city' => 'Shanghai',
        'state' => 'Shanghai',
        'country' => 'CN',
        'postcode' => 200000,
    ];

    expect(ValidatorUtil::validateOrganization($organization))->toBeEmpty();
});

test('validateOrganization 缺少必填字段返回错误', function () {

    $org = ['name' => 'ACME Corp'];
    $result = ValidatorUtil::validateOrganization($org);
    expect($result)->toHaveKey('registration_number')
        ->toHaveKey('phone')
        ->toHaveKey('address');
});

test('validateOrganization 国家代码必须 2 位', function () {

    $org = [
        'name' => 'ACME Corp',
        'registration_number' => '123456789012345678',
        'phone' => '13800138000',
        'address' => '123 Main Street',
        'city' => 'Shanghai',
        'state' => 'Shanghai',
        'country' => 'China',
        'postcode' => '200000',
    ];
    $result = ValidatorUtil::validateOrganization($org);
    expect($result)->toHaveKey('country');
});

// ==========================================
// validateSansMaxCount
// ==========================================

test('validateSansMaxCount 域名数量未超限通过', function () {
    $product = [
        'standard_max' => 10,
        'wildcard_max' => 5,
        'total_max' => 15,
        'gift_root_domain' => 0,
    ];
    $result = ValidatorUtil::validateSansMaxCount($product, 'a.com,b.com,*.c.com');
    expect($result)->toBeEmpty();
});

test('validateSansMaxCount 标准域名超限返回错误', function () {
    $product = [
        'standard_max' => 1,
        'wildcard_max' => 5,
        'total_max' => 10,
        'gift_root_domain' => 0,
    ];
    $result = ValidatorUtil::validateSansMaxCount($product, 'a.com,b.com');
    expect($result)->toHaveKey('standard');
});

test('validateSansMaxCount 通配符域名超限返回错误', function () {
    $product = [
        'standard_max' => 10,
        'wildcard_max' => 1,
        'total_max' => 10,
        'gift_root_domain' => 0,
    ];
    $result = ValidatorUtil::validateSansMaxCount($product, '*.a.com,*.b.com');
    expect($result)->toHaveKey('wildcard');
});

test('validateSansMaxCount 总数超限返回错误', function () {
    $product = [
        'standard_max' => 10,
        'wildcard_max' => 10,
        'total_max' => 2,
        'gift_root_domain' => 0,
    ];
    $result = ValidatorUtil::validateSansMaxCount($product, 'a.com,b.com,c.com');
    expect($result)->toHaveKey('total');
});

test('validateSansMaxCount 非字符串域名返回错误', function () {
    $product = [
        'standard_max' => 10,
        'wildcard_max' => 5,
        'total_max' => 15,
        'gift_root_domain' => 0,
    ];
    $result = ValidatorUtil::validateSansMaxCount($product, ['a.com']);
    expect($result)->toContain('域名必须是字符串类型');
});

test('validateSansMaxCount 赠送根域名时 total_max>1 自动补齐后验证', function () {
    $product = [
        'standard_max' => 10,
        'wildcard_max' => 5,
        'total_max' => 4,
        'gift_root_domain' => 1,
    ];
    // www.a.com 补齐 a.com，b.com 补齐 www.b.com，实际变 4 个域名
    $result = ValidatorUtil::validateSansMaxCount($product, 'www.a.com,b.com');
    expect($result)->toBeEmpty();
});

// ==========================================
// validateDomains
// ==========================================

test('validateDomains 合法域名列表通过', function () {
    $product = [
        'standard_max' => 10,
        'wildcard_max' => 5,
        'total_max' => 15,
        'gift_root_domain' => 0,
        'common_name_types' => ['standard', 'wildcard'],
        'alternative_name_types' => ['standard', 'wildcard'],
    ];
    $result = ValidatorUtil::validateDomains('example.com,test.com', $product);
    expect($result)->toBeEmpty();
});

test('validateDomains 空产品返回错误', function () {
    $result = ValidatorUtil::validateDomains('example.com', []);
    expect($result)->toBe('未找到产品');
});

test('validateDomains 非字符串返回错误', function () {
    $result = ValidatorUtil::validateDomains(['example.com'], ['id' => 1]);
    expect($result)->toBe('域名必须是字符串类型');
});

test('validateDomains 重复域名返回错误', function () {
    $product = [
        'standard_max' => 10,
        'wildcard_max' => 5,
        'total_max' => 15,
        'gift_root_domain' => 0,
        'common_name_types' => ['standard', 'wildcard'],
        'alternative_name_types' => ['standard', 'wildcard'],
    ];
    $result = ValidatorUtil::validateDomains('example.com,example.com', $product);
    expect($result)->toHaveKey('repeat');
});

test('validateDomains 通配符域名使用 http 验证方法报错', function () {
    $product = [
        'standard_max' => 10,
        'wildcard_max' => 5,
        'total_max' => 15,
        'gift_root_domain' => 0,
        'common_name_types' => ['standard', 'wildcard'],
        'alternative_name_types' => ['standard', 'wildcard'],
    ];
    $params = ['validation_method' => 'http'];
    $result = ValidatorUtil::validateDomains('*.example.com', $product, $params);
    expect($result)->not->toBeEmpty();
});

test('validateDomains IP 地址使用非文件验证方法报错', function () {
    $product = [
        'standard_max' => 10,
        'wildcard_max' => 5,
        'total_max' => 15,
        'gift_root_domain' => 0,
        'common_name_types' => ['standard', 'ipv4'],
        'alternative_name_types' => ['standard', 'ipv4'],
    ];
    $params = ['validation_method' => 'dns'];
    $result = ValidatorUtil::validateDomains('1.2.3.4', $product, $params);
    expect($result)->not->toBeEmpty();
});

// ==========================================
// validateSMIMEParams
// ==========================================

test('validateSMIMEParams mailbox 类型只需 email', function () {
    $product = ['code' => 'certum-smime-mailbox'];
    $params = ['email' => 'test@test.com'];
    $result = ValidatorUtil::validateSMIMEParams($params, $product);
    expect($result)->toBeEmpty();
});

test('validateSMIMEParams mailbox 类型缺少 email 报错', function () {
    $product = ['code' => 'certum-smime-mailbox'];
    $params = [];
    $result = ValidatorUtil::validateSMIMEParams($params, $product);
    expect($result)->toHaveKey('email');
});

test('validateSMIMEParams mailbox 类型邮箱格式错误', function () {
    $product = ['code' => 'certum-smime-mailbox'];
    $params = ['email' => 'invalid'];
    $result = ValidatorUtil::validateSMIMEParams($params, $product);
    expect($result)->toHaveKey('email');
});

test('validateSMIMEParams individual 类型需要 contact', function () {
    $product = ['code' => 'certum-smime-individual'];
    $params = ['email' => 'test@test.com'];
    $result = ValidatorUtil::validateSMIMEParams($params, $product);
    expect($result)->toHaveKey('contact');
});

test('validateSMIMEParams individual 类型有 contact 通过', function () {
    $product = ['code' => 'certum-smime-individual'];
    $params = ['email' => 'test@test.com', 'contact' => ['first_name' => 'John']];
    $result = ValidatorUtil::validateSMIMEParams($params, $product);
    expect($result)->not->toHaveKey('contact');
});

test('validateSMIMEParams sponsor 类型需要 contact 和 organization', function () {
    $product = ['code' => 'certum-smime-sponsor'];
    $params = ['email' => 'test@test.com'];
    $result = ValidatorUtil::validateSMIMEParams($params, $product);
    expect($result)->toHaveKey('contact')
        ->toHaveKey('organization');
});

test('validateSMIMEParams organization 类型需要 contact 和 organization', function () {
    $product = ['code' => 'certum-smime-organization'];
    $params = ['email' => 'test@test.com'];
    $result = ValidatorUtil::validateSMIMEParams($params, $product);
    expect($result)->toHaveKey('contact')
        ->toHaveKey('organization');
});

test('validateSMIMEParams 无法识别的类型返回 product 错误', function () {
    $product = ['code' => 'certum-smime-unknown'];
    $params = ['email' => 'test@test.com'];
    $result = ValidatorUtil::validateSMIMEParams($params, $product);
    expect($result)->toHaveKey('product');
});

// ==========================================
// validateCodeSignParams
// ==========================================

test('validateCodeSignParams 有 organization 通过', function () {
    $result = ValidatorUtil::validateCodeSignParams(['organization' => ['name' => 'Corp']]);
    expect($result)->toBeEmpty();
});

test('validateCodeSignParams 缺少 organization 报错', function () {
    $result = ValidatorUtil::validateCodeSignParams([]);
    expect($result)->toHaveKey('organization');
});

// ==========================================
// validateDocSignParams
// ==========================================

test('validateDocSignParams 有 organization 通过', function () {
    $result = ValidatorUtil::validateDocSignParams(['organization' => ['name' => 'Corp']]);
    expect($result)->toBeEmpty();
});

test('validateDocSignParams 缺少 organization 报错', function () {
    $result = ValidatorUtil::validateDocSignParams([]);
    expect($result)->toHaveKey('organization');
});
