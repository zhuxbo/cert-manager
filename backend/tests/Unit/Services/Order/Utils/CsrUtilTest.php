<?php

use App\Services\Order\Utils\CsrUtil;
use Tests\TestCase;

uses(TestCase::class);

// ==================== generate ====================

test('generate rsa csr', function () {
    $params = [
        'domains' => 'example.com',
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
    ];

    $result = CsrUtil::generate($params);

    expect($result)->toHaveKey('csr');
    expect($result)->toHaveKey('private_key');
    expect($result['csr'])->toContain('BEGIN CERTIFICATE REQUEST');
    expect($result['private_key'])->toContain('BEGIN PRIVATE KEY');
});

test('generate rsa 4096 csr', function () {
    $params = [
        'domains' => 'example.com',
        'encryption' => ['alg' => 'rsa', 'bits' => 4096],
    ];

    $result = CsrUtil::generate($params);

    expect($result)->toHaveKey('csr');
    expect($result)->toHaveKey('private_key');

    // 验证密钥长度
    $privateKey = openssl_pkey_get_private($result['private_key']);
    $details = openssl_pkey_get_details($privateKey);
    expect($details['bits'])->toBe(4096);
});

test('generate ecdsa csr', function () {
    $params = [
        'domains' => 'example.com',
        'encryption' => ['alg' => 'ecdsa', 'bits' => 256],
    ];

    $result = CsrUtil::generate($params);

    expect($result)->toHaveKey('csr');
    expect($result)->toHaveKey('private_key');
    expect($result['csr'])->toContain('BEGIN CERTIFICATE REQUEST');
    // PHP 8+ 使用 PKCS#8 格式（通用私钥格式），而非 EC 专用格式
    expect($result['private_key'])->toContain('BEGIN PRIVATE KEY');
});

test('generate sm2 csr（真实走 gmOpenssl，靠系统 OpenSSL 3.0+ 原生 SM2）', function () {
    $params = [
        'domains' => 'sm2.example.com',
        'encryption' => ['alg' => 'sm2'],
    ];

    $result = CsrUtil::generate($params);

    expect($result)->toHaveKey('csr');
    expect($result)->toHaveKey('private_key');
    expect($result['csr'])->toContain('BEGIN CERTIFICATE REQUEST');
    // SM2 私钥为 EC 格式，已剥离 ecparam 附带的 PARAMETERS 块（部分国密 nginx 只认纯私钥块）
    expect($result['private_key'])->toContain('PRIVATE KEY');
    expect($result['private_key'])->not->toContain('EC PARAMETERS');

    // 回归守卫：SM2 CSR 的 SubjectPublicKeyInfo 必须是标准 id-ecPublicKey 编码（RFC 5480 / OpenSSL 3），
    // 而非非标准的 dual-sm2（algorithm 字段填 sm2 曲线 OID）编码 —— 后者会被国密 CA（如 Keeptrust）
    // 拒为"csr 解析失败"。id-ecPublicKey OID 1.2.840.10045.2.1 的 DER 内容字节为 2a8648ce3d0201；
    // dual-sm2 编码的 algorithm 用 sm2 曲线 OID、不含此串，故以此区分、钉死走系统 OpenSSL 3 的标准编码。
    $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $result['csr']));
    expect(str_contains($der, hex2bin('2a8648ce3d0201')))->toBeTrue();
});

test('generate sm2 临时文件 finally 清理，私钥不留盘', function () {
    $dir = storage_path('app/sm2');
    $before = is_dir($dir) ? glob($dir.'/*') : [];

    CsrUtil::generate(['domains' => 'sm2.example.com', 'encryption' => ['alg' => 'sm2']]);

    $after = is_dir($dir) ? glob($dir.'/*') : [];
    expect($after)->toBe($before); // 无残留临时目录（私钥敏感，必须清理）
});

test('buildSm2Subject 构建主题串并转义 / 分隔符', function () {
    $reflect = new ReflectionMethod(CsrUtil::class, 'buildSm2Subject');
    $subject = $reflect->invoke(null, [
        'commonName' => 'a.com',
        'countryName' => 'CN',
        'stateOrProvinceName' => 'Beijing',
        'localityName' => 'Beijing',
        'organizationName' => 'GM/Co',
    ]);

    expect($subject)->toBe('/CN=a.com/C=CN/ST=Beijing/L=Beijing/O=GM\/Co');
});

test('generate with organization', function () {
    $params = [
        'domains' => 'example.com',
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
        'organization' => [
            'name' => 'Test Company',
            'country' => 'US',
            'state' => 'California',
            'city' => 'San Francisco',
        ],
    ];

    $result = CsrUtil::generate($params);

    $csrInfo = openssl_csr_get_subject($result['csr']);
    expect($csrInfo['O'])->toBe('Test Company');
    expect($csrInfo['C'])->toBe('US');
    expect($csrInfo['ST'])->toBe('California');
    expect($csrInfo['L'])->toBe('San Francisco');
});

// ==================== getEncryptionParams ====================

test('get encryption params', function (array $input, array $expected) {
    $result = CsrUtil::getEncryptionParams($input);

    foreach ($expected as $key => $value) {
        expect($result[$key])->toBe($value);
    }
})->with([
    '默认值' => [
        [],
        ['alg' => 'rsa', 'bits' => 2048, 'digest_alg' => 'sha256'],
    ],
    'RSA 4096' => [
        ['encryption' => ['alg' => 'rsa', 'bits' => 4096]],
        ['alg' => 'rsa', 'bits' => 4096, 'digest_alg' => 'sha256'],
    ],
    'ECDSA 256' => [
        ['encryption' => ['alg' => 'ecdsa', 'bits' => 256]],
        ['alg' => 'ecdsa', 'curve' => 'prime256v1', 'digest_alg' => 'sha256'],
    ],
    'ECDSA 384' => [
        ['encryption' => ['alg' => 'ecdsa', 'bits' => 384]],
        ['alg' => 'ecdsa', 'curve' => 'secp384r1', 'digest_alg' => 'sha256'],
    ],
    'ECDSA 521' => [
        ['encryption' => ['alg' => 'ecdsa', 'bits' => 521]],
        ['alg' => 'ecdsa', 'curve' => 'secp521r1', 'digest_alg' => 'sha256'],
    ],
    'SHA384 摘要' => [
        ['encryption' => ['alg' => 'rsa', 'bits' => 2048, 'digest_alg' => 'sha384']],
        ['alg' => 'rsa', 'bits' => 2048, 'digest_alg' => 'sha384'],
    ],
    'CodeSign 强制 4096' => [
        ['encryption' => ['alg' => 'rsa', 'bits' => 2048], 'product' => ['product_type' => 'codesign']],
        ['alg' => 'rsa', 'bits' => 4096, 'digest_alg' => 'sha256'],
    ],
    'DocSign 强制 4096' => [
        ['encryption' => ['alg' => 'rsa', 'bits' => 2048], 'product' => ['product_type' => 'docsign']],
        ['alg' => 'rsa', 'bits' => 4096, 'digest_alg' => 'sha256'],
    ],
    '无效算法回退' => [
        ['encryption' => ['alg' => 'invalid']],
        ['alg' => 'rsa', 'bits' => 2048, 'digest_alg' => 'sha256'],
    ],
    '无效位数回退' => [
        ['encryption' => ['alg' => 'rsa', 'bits' => 1024]],
        ['alg' => 'rsa', 'bits' => 2048, 'digest_alg' => 'sha256'],
    ],
    'SM2 固定曲线+SM3' => [
        ['encryption' => ['alg' => 'sm2']],
        ['alg' => 'sm2', 'curve' => 'SM2', 'digest_alg' => 'sm3'],
    ],
    'SM2 强制 SM3（传 sha256 也回 sm3）' => [
        ['encryption' => ['alg' => 'sm2', 'digest_alg' => 'sha256']],
        ['alg' => 'sm2', 'curve' => 'SM2', 'digest_alg' => 'sm3'],
    ],
]);

// ==================== getInfoParams ====================

test('get info params ssl product', function () {
    $params = [
        'domains' => 'example.com,www.example.com',
        'product' => ['product_type' => 'ssl'],
        'organization' => [
            'name' => 'Test Company',
            'country' => 'CN',
            'state' => 'Beijing',
            'city' => 'Beijing',
        ],
    ];

    $result = CsrUtil::getInfoParams($params);

    expect($result['commonName'])->toBe('example.com');
    expect($result['organizationName'])->toBe('Test Company');
    expect($result['countryName'])->toBe('CN');
});

test('get info params smime mailbox', function () {
    $params = [
        'email' => 'test@example.com',
        'product' => ['product_type' => 'smime', 'code' => 'smime-mailbox'],
    ];

    $result = CsrUtil::getInfoParams($params);

    expect($result['commonName'])->toBe('test@example.com');
});

test('get info params smime individual', function () {
    $params = [
        'email' => 'test@example.com',
        'product' => ['product_type' => 'smime', 'code' => 'smime-individual'],
        'contact' => ['first_name' => 'John', 'last_name' => 'Doe'],
    ];

    $result = CsrUtil::getInfoParams($params);

    expect($result['commonName'])->toBe('John Doe');
});

test('get info params smime organization', function () {
    $params = [
        'email' => 'test@example.com',
        'product' => ['product_type' => 'smime', 'code' => 'smime-organization'],
        'organization' => ['name' => 'Test Company'],
    ];

    $result = CsrUtil::getInfoParams($params);

    expect($result['commonName'])->toBe('Test Company');
});

test('get info params codesign', function () {
    $params = [
        'product' => ['product_type' => 'codesign'],
        'organization' => ['name' => 'Software Company'],
    ];

    $result = CsrUtil::getInfoParams($params);

    expect($result['commonName'])->toBe('Software Company');
});

test('get info params default values', function () {
    $params = [
        'domains' => 'example.com',
    ];

    $result = CsrUtil::getInfoParams($params);

    expect($result['countryName'])->toBe('CN');
    expect($result['stateOrProvinceName'])->toBe('Shanghai');
    expect($result['localityName'])->toBe('Shanghai');
});

test('get info params certum ev', function () {
    $params = [
        'domains' => 'example.com',
        'product' => ['brand' => 'Certum', 'validation_type' => 'EV'],
        'organization' => [
            'name' => 'Test Company',
            'country' => 'CN',
            'state' => 'Shanghai',
            'city' => 'Shanghai',
            'category' => 'Private Organization',
            'registration_number' => '123456789',
        ],
    ];

    $result = CsrUtil::getInfoParams($params);

    expect($result['jurisdictionCountryName'])->toBe('CN');
    expect($result['jurisdictionStateOrProvinceName'])->toBe('Shanghai');
    expect($result['jurisdictionLocalityName'])->toBe('Shanghai');
    expect($result['businessCategory'])->toBe('Private Organization');
    expect($result['serialNumber'])->toBe('123456789');
});

// ==================== getSMIMEType ====================

test('get smime type', function (array $product, string $expected) {
    expect(CsrUtil::getSMIMEType($product))->toBe($expected);
})->with([
    'mailbox' => [['code' => 'smime-mailbox-basic'], 'mailbox'],
    'individual' => [['code' => 'smime-individual-pro'], 'individual'],
    'sponsor' => [['code' => 'smime-sponsor-enterprise'], 'sponsor'],
    'organization' => [['code' => 'smime-organization'], 'organization'],
    '大写' => [['code' => 'SMIME-MAILBOX'], 'mailbox'],
    '使用api_id' => [['api_id' => 'smime-individual'], 'individual'],
    '未知类型' => [['code' => 'smime-unknown'], 'unknown'],
    '空数组' => [[], 'unknown'],
]);

// ==================== matchKey ====================

test('match key valid', function () {
    $params = [
        'domains' => 'example.com',
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
    ];

    $result = CsrUtil::generate($params);

    expect(CsrUtil::matchKey($result['csr'], $result['private_key']))->toBeTrue();
});

test('match key invalid', function () {
    $params1 = ['domains' => 'example.com', 'encryption' => ['alg' => 'rsa', 'bits' => 2048]];
    $params2 = ['domains' => 'other.com', 'encryption' => ['alg' => 'rsa', 'bits' => 2048]];

    $result1 = CsrUtil::generate($params1);
    $result2 = CsrUtil::generate($params2);

    expect(CsrUtil::matchKey($result1['csr'], $result2['private_key']))->toBeFalse();
});

test('match key invalid key', function () {
    $params = ['domains' => 'example.com', 'encryption' => ['alg' => 'rsa', 'bits' => 2048]];
    $result = CsrUtil::generate($params);

    expect(CsrUtil::matchKey($result['csr'], 'invalid-key'))->toBeFalse();
});

test('match key invalid csr', function () {
    $params = ['domains' => 'example.com', 'encryption' => ['alg' => 'rsa', 'bits' => 2048]];
    $result = CsrUtil::generate($params);

    expect(CsrUtil::matchKey('invalid-csr', $result['private_key']))->toBeFalse();
});

// ==================== checkDomain ====================

test('check domain valid', function () {
    $params = [
        'domains' => 'example.com',
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
    ];

    $result = CsrUtil::generate($params);

    // 不抛出异常即为成功
    CsrUtil::checkDomain($result['csr'], 'example.com');
    expect(true)->toBeTrue();
});

test('check domain invalid', function () {
    $params = [
        'domains' => 'example.com',
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
    ];

    $result = CsrUtil::generate($params);

    CsrUtil::checkDomain($result['csr'], 'other.com');
})->throws(Exception::class);

// ==================== auto ====================

test('auto generate csr', function () {
    $params = [
        'csr_generate' => 1,
        'domains' => 'example.com',
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
    ];

    $result = CsrUtil::auto($params);

    expect($result)->toHaveKey('csr');
    expect($result)->toHaveKey('private_key');
});

test('auto with existing csr', function () {
    $generated = CsrUtil::generate([
        'domains' => 'example.com',
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
    ]);

    $params = [
        'csr_generate' => 0,
        'csr' => $generated['csr'],
        'domains' => 'example.com',
    ];

    $result = CsrUtil::auto($params);

    expect($result['csr'])->toBe($generated['csr']);
});

test('auto smime skips domain check', function () {
    $generated = CsrUtil::generate([
        'domains' => 'not-a-domain',
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
        'organization' => ['name' => 'Test'],
    ]);

    $params = [
        'csr_generate' => 0,
        'csr' => $generated['csr'],
        'domains' => 'different.com',
        'product' => ['product_type' => 'smime'],
    ];

    // 不应该抛出异常，因为 smime 跳过域名检查
    $result = CsrUtil::auto($params);
    expect($result)->toHaveKey('csr');
});

test('auto empty csr throws error', function () {
    $params = [
        'csr_generate' => 0,
        'csr' => '',
        'domains' => 'example.com',
    ];

    CsrUtil::auto($params);
})->throws(Exception::class);
