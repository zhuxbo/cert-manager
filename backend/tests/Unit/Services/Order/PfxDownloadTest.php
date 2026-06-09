<?php

use App\Exceptions\ApiResponseException;
use App\Models\Cert;
use App\Models\Order;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Order\Action;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 内存生成一对匹配的 RSA 证书 + 私钥（PEM）。不走 BinaryLocator CLI，靠 PHP openssl 扩展，
 * 到处可用；只有 addCertToZip 内部生成 PFX 才依赖容器真 OpenSSL CLI。
 *
 * @return array{0:string,1:string} [certPem, privateKeyPem]
 */
function makeRsaCertAndKey(string $cn = 'pfx.example.com'): array
{
    $keyPair = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($keyPair, $privateKeyPem);
    $csr = openssl_csr_new(['commonName' => $cn], $keyPair, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $keyPair, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $certPem);

    return [$certPem, $privateKeyPem];
}

/**
 * 构造一个 latestCert 为有效 RSA 证书 + 匹配私钥的 Order。
 *
 * intermediate_cert 是 Cert 的 computed accessor（依赖 issuer + Chain 表，经 cert.chainMap 容器缓存），
 * 不是真实列；download() 生产入口会过滤空 intermediate。测试还原此前提：注入 chainMap + 设 issuer，
 * 让 -certfile 拿到有效证书，否则 openssl pkcs12 报 "Could not read any extra certificates" 不出 pfx。
 */
function makeMatchedOrder(string $cn = 'pfx.example.com'): Order
{
    [$certPem, $keyPem] = makeRsaCertAndKey($cn);
    [$caPem] = makeRsaCertAndKey('Intermediate CA '.$cn);
    app()->instance('cert.chainMap', ['Test CA' => $caPem]);

    $cert = new Cert(['common_name' => $cn, 'cert' => $certPem, 'private_key' => $keyPem]);
    $cert->issuer = 'Test CA';
    $order = new Order;
    $order->setRelation('latestCert', $cert);

    return $order;
}

/** 从 zip 取某条目内容，缺失返回 null。 */
function entryFromZip(string $zipPath, string $entry): ?string
{
    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        return null;
    }
    $content = $zip->getFromName($entry);
    $zip->close();

    return $content === false ? null : $content;
}

/** zip 内全部条目名。 */
function zipEntryNames(string $zipPath): array
{
    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        return [];
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    return $names;
}

/** 唯一临时目录，避免 --parallel 多进程争抢 addCertToZip 写的固定文件名（temp.pfx 等）。 */
function makePfxTempDir(): string
{
    $dir = sys_get_temp_dir().'/pfxtest_'.uniqid('', true);
    mkdir($dir, 0700, true);

    return $dir;
}

function cleanupDir(string $dir): void
{
    foreach (glob($dir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

/** 跳过条件：BinaryLocator 拒绝 LibreSSL（本机 macOS），无真 OpenSSL CLI 则跳过算法断言。 */
function skipIfNoRealOpenssl(TestCase $test): void
{
    try {
        app(BinaryLocator::class)->openssl();
    } catch (BinaryNotFoundException $e) {
        $test->markTestSkipped('需要真 OpenSSL CLI（BinaryLocator 拒 LibreSSL）：'.$e->getMessage());
    }
}

/**
 * 断言闭包抛 ApiResponseException 且响应 msg 含指定子串。自包含、不依赖其它测试文件的全局 helper
 * （避免按路径单跑本目录时 Acme/ActionTest 未加载导致 undefined function）。消息在 apiResponse['msg']，
 * ApiResponseException extends HttpResponseException，getMessage() 为空。
 */
function expectPfxApiError(Closure $fn, string $msgContains): void
{
    try {
        $fn();
    } catch (ApiResponseException $e) {
        expect($e->getApiResponse()['msg'] ?? '')->toContain($msgContains);

        return;
    }
    throw new RuntimeException("预期抛 ApiResponseException（含「{$msgContains}」），但未抛出");
}

/** 调 protected addCertToZip 生成 zip 到指定路径。 */
function buildCertZip(Order $order, string $tempDir, string $zipPath, string $type): void
{
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $reflect = new ReflectionMethod(Action::class, 'addCertToZip');
    $reflect->invoke(app(Action::class), $order, $zip, $tempDir, [], $type);
    $zip->close();
}

// pbeWithSHA1And3-KeyTripleDES-CBC = 1.2.840.113549.1.12.1.3
const OID_PBE_SHA1_3DES = '060a2a864886f70d010c0103';
// aes-256-cbc = 2.16.840.1.101.3.4.1.42（OpenSSL 3.x PKCS12 默认 PBES2 用，老 Windows 无法导入）
const OID_AES_256_CBC = '060960864801650304012a';

test('IIS PFX 用 PBE-SHA1-3DES 加密以兼容老 Windows，绝不退回 AES-256', function () {
    skipIfNoRealOpenssl($this);

    $order = makeMatchedOrder('pfx.example.com');
    $tempDir = makePfxTempDir();
    $zipPath = tempnam(sys_get_temp_dir(), 'pfxzip');
    buildCertZip($order, $tempDir, $zipPath, 'iis');

    $pfx = entryFromZip($zipPath, 'pfx.example.com/iis/pfx.example.com.pfx');
    cleanupDir($tempDir);
    @unlink($zipPath);

    expect($pfx)->not->toBeNull();

    // 1) 可被导入：密码正确、结构有效，能读出证书 + 私钥
    $out = [];
    expect(openssl_pkcs12_read($pfx, $out, '123456'))->toBeTrue();
    expect($out)->toHaveKeys(['cert', 'pkey']);

    // 2) 算法锁定（DER 字节级，不依赖外部解析）：含 3DES OID、不含 AES-256 OID
    expect(str_contains($pfx, hex2bin(OID_PBE_SHA1_3DES)))->toBeTrue();
    expect(str_contains($pfx, hex2bin(OID_AES_256_CBC)))->toBeFalse();
});

test('IIS 模式私钥缺失时硬报错', function () {
    [$certPem] = makeRsaCertAndKey('p1.example.com');
    $cert = new Cert(['common_name' => 'p1.example.com', 'cert' => $certPem]); // 无 private_key
    $order = new Order;
    $order->setRelation('latestCert', $cert);

    $tempDir = makePfxTempDir();
    $zipPath = tempnam(sys_get_temp_dir(), 'pfxzip');
    expectPfxApiError(fn () => buildCertZip($order, $tempDir, $zipPath, 'iis'), '私钥不存在');
    cleanupDir($tempDir);
    @unlink($zipPath);
});

test('IIS 模式私钥与证书不匹配时硬报错', function () {
    [$certPem] = makeRsaCertAndKey('p1b.example.com');
    [, $otherKey] = makeRsaCertAndKey('other.example.com'); // 另一对的私钥，不匹配
    $cert = new Cert(['common_name' => 'p1b.example.com', 'cert' => $certPem, 'private_key' => $otherKey]);
    $order = new Order;
    $order->setRelation('latestCert', $cert);

    $tempDir = makePfxTempDir();
    $zipPath = tempnam(sys_get_temp_dir(), 'pfxzip');
    expectPfxApiError(fn () => buildCertZip($order, $tempDir, $zipPath, 'iis'), '私钥与证书不匹配');
    cleanupDir($tempDir);
    @unlink($zipPath);
});

test('all 模式 openssl 不可用时静默跳过 PFX，其它格式照出且不抛错', function () {
    $this->mock(BinaryLocator::class, function ($mock) {
        $mock->shouldReceive('openssl')->andThrow(new BinaryNotFoundException('openssl'));
    });

    $order = makeMatchedOrder('p2.example.com');
    $tempDir = makePfxTempDir();
    $zipPath = tempnam(sys_get_temp_dir(), 'pfxzip');
    buildCertZip($order, $tempDir, $zipPath, 'all'); // 不抛
    $names = zipEntryNames($zipPath);
    cleanupDir($tempDir);
    @unlink($zipPath);

    expect($names)->toContain('p2.example.com/nginx/p2.example.com.crt');
    expect(collect($names)->contains(fn ($n) => str_contains($n, 'iis/')))->toBeFalse();
});

test('IIS 模式 openssl 不可用时硬报错', function () {
    $this->mock(BinaryLocator::class, function ($mock) {
        $mock->shouldReceive('openssl')->andThrow(new BinaryNotFoundException('openssl'));
    });

    $order = makeMatchedOrder('p2b.example.com');
    $tempDir = makePfxTempDir();
    $zipPath = tempnam(sys_get_temp_dir(), 'pfxzip');
    expectPfxApiError(fn () => buildCertZip($order, $tempDir, $zipPath, 'iis'), 'OpenSSL 不可用');
    cleanupDir($tempDir);
    @unlink($zipPath);
});

test('all 模式输出 RSA 传统格式私钥', function () {
    skipIfNoRealOpenssl($this);

    $order = makeMatchedOrder('p3.example.com');
    $tempDir = makePfxTempDir();
    $zipPath = tempnam(sys_get_temp_dir(), 'pfxzip');
    buildCertZip($order, $tempDir, $zipPath, 'all');
    $names = zipEntryNames($zipPath);
    cleanupDir($tempDir);
    @unlink($zipPath);

    expect($names)->toContain('p3.example.com/rsa_key/p3.example.com-rsa.key');
});
