<?php

use App\Models\Cert;
use App\Models\Order;
use App\Services\Order\Action;
use Tests\TestCase;

uses(TestCase::class);

function sm2ZipNames(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    return $names;
}

test('国密证书下载只出 nginx 双证书包（签名+加密），不含 apache/iis', function () {
    // 内存构造，不入库：encryption_alg=SM2 触发国密分支（与前端 isSM2 / Deploy gate 同口径）
    $cert = new Cert([
        'common_name' => 'sm2.example.com',
        'encryption_alg' => 'SM2',
        'cert' => "-----BEGIN CERTIFICATE-----\nSIGN\n-----END CERTIFICATE-----",
        'private_key' => 'SIGN-KEY',
        'enc_cert' => "-----BEGIN CERTIFICATE-----\nENC\n-----END CERTIFICATE-----",
        'enc_key' => 'ENC-KEY-0016',
        'enc_key2' => 'ENC-KEY-0009',
    ]);
    $order = new Order;
    $order->setRelation('latestCert', $cert);

    $zipPath = tempnam(sys_get_temp_dir(), 'gmzip');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $reflect = new ReflectionMethod(Action::class, 'addCertToZip');
    $reflect->invoke(app(Action::class), $order, $zip, sys_get_temp_dir(), [], 'all');
    $zip->close();

    $names = sm2ZipNames($zipPath);
    @unlink($zipPath);

    $base = 'sm2.example.com/nginx/sm2.example.com';
    expect($names)->toContain($base.'_sign.crt');
    expect($names)->toContain($base.'_sign.key');
    expect($names)->toContain($base.'_enc.crt');
    expect($names)->toContain($base.'_enc.key');
    expect($names)->toContain($base.'_enc_gmt0016.key');
    expect($names)->toContain($base.'_enc_gmt0009.key');
    expect($names)->toContain('sm2.example.com/nginx/说明.txt');

    // 国密只出 nginx，不含其它服务器格式
    expect(collect($names)->contains(fn ($n) => str_contains($n, 'apache/') || str_contains($n, 'iis/') || str_contains($n, 'tomcat/')))->toBeFalse();
});

test('加密私钥缺失致 enc 不成对时降级仅签名，不出残缺加密包（gateway 未就绪）', function () {
    $cert = new Cert([
        'common_name' => 'sm2b.example.com',
        'encryption_alg' => 'SM2',
        'cert' => "-----BEGIN CERTIFICATE-----\nSIGN\n-----END CERTIFICATE-----",
        'enc_cert' => "-----BEGIN CERTIFICATE-----\nENC\n-----END CERTIFICATE-----",
        // enc_key / enc_key2 缺失
    ]);
    $order = new Order;
    $order->setRelation('latestCert', $cert);

    $zipPath = tempnam(sys_get_temp_dir(), 'gmzip');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $reflect = new ReflectionMethod(Action::class, 'addCertToZip');
    $reflect->invoke(app(Action::class), $order, $zip, sys_get_temp_dir(), [], 'all');
    $zip->close();

    $names = sm2ZipNames($zipPath);
    @unlink($zipPath);

    $base = 'sm2b.example.com/nginx/sm2b.example.com';
    expect($names)->toContain($base.'_sign.crt');
    // enc 不成对（有加密证书但无加密私钥）→ 整组加密降级，不写任何 _enc 残缺文件
    expect(collect($names)->contains(fn ($n) => str_contains($n, '_enc')))->toBeFalse();
    expect($names)->toContain('sm2b.example.com/nginx/说明.txt');
});

test('非国密证书（enc_cert 空）走普通格式分支，不出国密双证书文件', function () {
    $cert = new Cert([
        'common_name' => 'normal.example.com',
        'cert' => "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----",
        // 无 enc_cert
    ]);
    $order = new Order;
    $order->setRelation('latestCert', $cert);

    $zipPath = tempnam(sys_get_temp_dir(), 'normzip');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $reflect = new ReflectionMethod(Action::class, 'addCertToZip');
    $reflect->invoke(app(Action::class), $order, $zip, sys_get_temp_dir(), [], 'nginx');
    $zip->close();

    $names = sm2ZipNames($zipPath);
    @unlink($zipPath);

    // 普通 nginx 包，无国密 _sign/_enc 文件
    expect(collect($names)->contains(fn ($n) => str_contains($n, '_enc.crt') || str_contains($n, '_sign.crt')))->toBeFalse();
    expect($names)->toContain('normal.example.com/nginx/normal.example.com.crt');
});

test('encryption_alg=SM2 但 enc_cert 空（gateway 未就绪）仍强制国密 nginx 包：仅签名、不丢私钥、不出 apache/iis', function () {
    // 杀手场景：上游已签发签名证书（encryption_alg=SM2），但 CA/KGC 加密证书未就绪 → enc_cert 空。
    // 旧实现用 enc_cert 非空判定国密，会让此证书掉进普通格式分支：
    //   - openssl_x509_check_private_key 对 SM2 返回 false → 签名私钥 _sign.key 丢失
    //   - type=all 还会打出 apache/pem/iis 等无意义格式
    // 修复后按 encryption_alg 判定，强制走国密 nginx 分支、降级仅出签名。
    $cert = new Cert([
        'common_name' => 'sm2c.example.com',
        'encryption_alg' => 'SM2',
        'cert' => "-----BEGIN CERTIFICATE-----\nSIGN\n-----END CERTIFICATE-----",
        'private_key' => 'SIGN-KEY',
        // enc_cert / enc_key / enc_key2 全空：CA/KGC 未下发加密证书
    ]);
    $order = new Order;
    $order->setRelation('latestCert', $cert);

    $zipPath = tempnam(sys_get_temp_dir(), 'gmzip');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $reflect = new ReflectionMethod(Action::class, 'addCertToZip');
    // 用 type=all 触发普通逻辑的全部格式分支，验证国密判定能拦截在前
    $reflect->invoke(app(Action::class), $order, $zip, sys_get_temp_dir(), [], 'all');
    $zip->close();

    $names = sm2ZipNames($zipPath);
    @unlink($zipPath);

    $base = 'sm2c.example.com/nginx/sm2c.example.com';
    // 签名证书 + 签名私钥必须在（不因 PHP openssl 不支持 SM2 而丢失）
    expect($names)->toContain($base.'_sign.crt');
    expect($names)->toContain($base.'_sign.key');
    expect($names)->toContain('sm2c.example.com/nginx/说明.txt');
    // 加密证书未就绪：不写任何空的 _enc 文件
    expect(collect($names)->contains(fn ($n) => str_contains($n, '_enc')))->toBeFalse();
    // 仍强制国密：不出普通 apache/iis/tomcat/pem 格式
    expect(collect($names)->contains(fn ($n) => str_contains($n, 'apache/') || str_contains($n, 'iis/') || str_contains($n, 'tomcat/') || str_contains($n, 'pem/')))->toBeFalse();
    // 也不出普通 nginx 单证书文件（普通分支的 .crt 而非国密 _sign.crt）
    expect($names)->not->toContain('sm2c.example.com/nginx/sm2c.example.com.crt');
});
