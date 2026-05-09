<?php

use App\Services\Backup\BackupService;

// ensureMysqlClient 依赖 config()，需要启动 Laravel app（Unit 默认不挂 TestCase）
uses(Tests\TestCase::class);

test('filterStructureTables 剔除指定表', function () {
    $svc = new BackupService;
    $structure = [
        'tables' => [
            'users' => ['columns' => []],
            'admin_logs' => ['columns' => []],
            'jobs' => ['columns' => []],
            'orders' => ['columns' => []],
        ],
    ];

    $filtered = $svc->filterStructureTables($structure, ['admin_logs', 'jobs']);

    expect(array_keys($filtered['tables']))->toEqual(['users', 'orders']);
});

test('filterStructureTables 在空 ignore 列表时原样返回', function () {
    $svc = new BackupService;
    $structure = ['tables' => ['users' => ['columns' => []]]];

    expect($svc->filterStructureTables($structure, []))->toEqual($structure);
});

test('filterStructureTables 不影响其它顶层字段', function () {
    $svc = new BackupService;
    $structure = [
        'tables' => ['x' => ['c' => []], 'y' => ['c' => []]],
        'generated_at' => '2026-04-25 00:00:00',
    ];

    $filtered = $svc->filterStructureTables($structure, ['x']);

    expect($filtered['generated_at'])->toBe('2026-04-25 00:00:00')
        ->and(array_keys($filtered['tables']))->toEqual(['y']);
});

test('ensureMysqlClient: 配置为不存在的绝对路径时抛带安装提示的异常', function () {
    config(['database.backup.mysqldump_bin' => '/nonexistent/path/to/mysqldump']);

    $svc = new BackupService;

    expect(fn () => $svc->ensureMysqlClient('mysqldump'))
        ->toThrow(RuntimeException::class, 'mysqldump 不可执行');
});

test('ensureMysqlClient: PATH 中找不到二进制时抛简短异常', function () {
    config(['database.backup.mysql_bin' => '__definitely_not_exist_xyz__']);

    $svc = new BackupService;

    expect(fn () => $svc->ensureMysqlClient('mysql'))
        ->toThrow(RuntimeException::class, '未找到 mysql 命令');
});

test('installHintLines: mysql driver 返回 mysql-client 安装提示', function () {
    $lines = BackupService::installHintLines('mysql');

    expect($lines)->toBeArray()->not->toBeEmpty();
    expect(implode("\n", $lines))->toContain('mysql-client');
});

test('installHintLines: 不传 driver 时回落到当前 default connection 的 driver', function () {
    // default 已被测试环境设置为 mysql（.env），不传参时应返回 mysql 提示
    if (config('database.connections.'.config('database.default').'.driver') !== 'mysql') {
        test()->markTestSkipped('当前 default 不是 mysql');
    }
    $lines = BackupService::installHintLines();
    expect(implode("\n", $lines))->toContain('mysql-client');
});

test('ensureMysqlClient: 配置为合法 mysql 客户端绝对路径时返回该路径', function () {
    $bin = fakeMysqlClientBin('mysqldump');
    config(['database.backup.mysqldump_bin' => $bin]);

    $svc = new BackupService;
    expect($svc->ensureMysqlClient('mysqldump'))->toBe($bin);
});

test('ensureMysqlClient: 探测不区分 is_executable，可绕过 open_basedir', function () {
    // 行为锚点：probeExecutable 用 proc_open 而非 is_executable / file_exists；
    // 一个看起来像 mysql 客户端但不在 open_basedir 白名单的脚本仍能被识别。
    $bin = fakeMysqlClientBin('mysqldump');
    config(['database.backup.mysqldump_bin' => $bin]);

    $svc = new BackupService;
    expect($svc->ensureMysqlClient('mysqldump'))->toBe($bin);
});

test('ensureMysqlClient: 输出不含 mysql 客户端特征的脚本视为不可用', function () {
    $bogus = sys_get_temp_dir().'/bogus_'.uniqid().'.sh';
    file_put_contents($bogus, "#!/bin/sh\necho 'I am not mysqldump'\n");
    chmod($bogus, 0755);
    register_shutdown_function(static fn () => @unlink($bogus));

    config(['database.backup.mysqldump_bin' => $bogus]);
    $svc = new BackupService;

    expect(fn () => $svc->ensureMysqlClient('mysqldump'))
        ->toThrow(RuntimeException::class, 'mysqldump 不可执行');
});

test('resolveBackup: 非法 ID 格式返回 null（防路径穿越）', function () {
    $svc = new BackupService;

    expect($svc->resolveBackup('../../../etc/passwd'))->toBeNull()
        ->and($svc->resolveBackup('backup_short'))->toBeNull()
        ->and($svc->resolveBackup('BACKUP_20260101_000000'))->toBeNull()  // 大写不匹配
        ->and($svc->resolveBackup('backup_20260101_00000a'))->toBeNull(); // 非纯数字
});

// ----- 6-1 备份加密 -----

/**
 * 造一对 (key_hex, key_bin)，长度严格 32 字节。
 *
 * @return array{0:string,1:string}
 */
function makeBackupEncKey(): array
{
    $bin = random_bytes(32);
    $hex = bin2hex($bin);

    return [$hex, $bin];
}

test('encryptBackup → decryptBackup round-trip 内容一致', function () {
    [$hex] = makeBackupEncKey();
    config(['backup.enc_key' => $hex]);

    $svc = new BackupService;

    // 模拟备份内容（任意二进制）
    $payload = random_bytes(4096).str_repeat("INSERT INTO t VALUES (1, 'a');\n", 100);
    $src = sys_get_temp_dir().'/bk_src_'.uniqid().'.sql.gz';
    file_put_contents($src, $payload);
    register_shutdown_function(static fn () => @unlink($src));

    $enc = $svc->encryptBackup($src);
    register_shutdown_function(static fn () => @unlink($enc));

    expect($enc)->toBe("$src.enc")
        ->and(is_file($enc))->toBeTrue();

    // header magic / version / cipher_id 自检
    $header = file_get_contents($enc, false, null, 0, BackupService::ENC_HEADER_LENGTH);
    expect(substr($header, 0, 4))->toBe(BackupService::ENC_MAGIC)
        ->and(ord($header[4]))->toBe(BackupService::ENC_VERSION)
        ->and(ord($header[5]))->toBe(BackupService::ENC_CIPHER_AES256_CBC);

    // 解密回原文
    $out = sys_get_temp_dir().'/bk_out_'.uniqid().'.sql.gz';
    register_shutdown_function(static fn () => @unlink($out));
    $svc->decryptBackup($enc, $out);

    expect(file_get_contents($out))->toBe($payload);
});

test('encryptBackup: 缺失 BACKUP_ENC_KEY 时抛 RuntimeException + 提示离线保存', function () {
    config(['backup.enc_key' => null]);

    $svc = new BackupService;
    $src = sys_get_temp_dir().'/bk_src_'.uniqid().'.sql.gz';
    file_put_contents($src, 'dummy');
    register_shutdown_function(static fn () => @unlink($src));

    expect(fn () => $svc->encryptBackup($src))
        ->toThrow(RuntimeException::class, '丢失=备份不可恢复，请离线保存');
});

test('decryptBackup: 缺失 BACKUP_ENC_KEY 时抛 RuntimeException', function () {
    config(['backup.enc_key' => null]);

    $svc = new BackupService;
    $enc = sys_get_temp_dir().'/bk_enc_'.uniqid().'.sql.gz.enc';
    file_put_contents($enc, BackupService::ENC_MAGIC.chr(0x01).chr(0x01).str_repeat("\0", 16).'x');
    register_shutdown_function(static fn () => @unlink($enc));

    expect(fn () => $svc->decryptBackup($enc, $enc.'.out'))
        ->toThrow(RuntimeException::class, 'BACKUP_ENC_KEY 未配置');
});

test('encryptBackup: BACKUP_ENC_KEY 长度错误时抛 RuntimeException', function () {
    config(['backup.enc_key' => 'too_short_not_hex']);

    $svc = new BackupService;
    $src = sys_get_temp_dir().'/bk_src_'.uniqid().'.sql.gz';
    file_put_contents($src, 'dummy');
    register_shutdown_function(static fn () => @unlink($src));

    expect(fn () => $svc->encryptBackup($src))
        ->toThrow(RuntimeException::class, '64 个十六进制字符');
});

test('decryptBackup: 非 SBME magic 文件被拒绝', function () {
    [$hex] = makeBackupEncKey();
    config(['backup.enc_key' => $hex]);

    $svc = new BackupService;
    $bogus = sys_get_temp_dir().'/bk_bogus_'.uniqid().'.sql.gz';
    // gzip header (1f 8b) — 完全不是 SBME
    file_put_contents($bogus, "\x1f\x8b\x08\x00".str_repeat("\0", 30));
    register_shutdown_function(static fn () => @unlink($bogus));

    expect(fn () => $svc->decryptBackup($bogus, $bogus.'.out'))
        ->toThrow(RuntimeException::class, 'magic 不匹配');
});

test('decryptBackup: 文件过短（不足 header）被拒绝', function () {
    [$hex] = makeBackupEncKey();
    config(['backup.enc_key' => $hex]);

    $svc = new BackupService;
    $short = sys_get_temp_dir().'/bk_short_'.uniqid().'.enc';
    file_put_contents($short, 'SBME'); // 只 4 字节
    register_shutdown_function(static fn () => @unlink($short));

    expect(fn () => $svc->decryptBackup($short, $short.'.out'))
        ->toThrow(RuntimeException::class, '加密文件过短');
});

test('decryptBackup: 不支持的版本号被拒绝', function () {
    [$hex] = makeBackupEncKey();
    config(['backup.enc_key' => $hex]);

    $svc = new BackupService;
    $bogus = sys_get_temp_dir().'/bk_badver_'.uniqid().'.enc';
    // magic + version=0xFF + cipher_id=0x01 + 16 byte IV + 1 byte ciphertext
    file_put_contents(
        $bogus,
        BackupService::ENC_MAGIC.chr(0xFF).chr(0x01).str_repeat("\0", 16).'x'
    );
    register_shutdown_function(static fn () => @unlink($bogus));

    expect(fn () => $svc->decryptBackup($bogus, $bogus.'.out'))
        ->toThrow(RuntimeException::class, '不支持的加密格式版本');
});

test('decryptBackup: 不支持的 cipher_id 被拒绝', function () {
    [$hex] = makeBackupEncKey();
    config(['backup.enc_key' => $hex]);

    $svc = new BackupService;
    $bogus = sys_get_temp_dir().'/bk_badcipher_'.uniqid().'.enc';
    file_put_contents(
        $bogus,
        BackupService::ENC_MAGIC.chr(0x01).chr(0xFF).str_repeat("\0", 16).'x'
    );
    register_shutdown_function(static fn () => @unlink($bogus));

    expect(fn () => $svc->decryptBackup($bogus, $bogus.'.out'))
        ->toThrow(RuntimeException::class, '不支持的加密算法 ID');
});

test('decryptBackup: 错误密钥导致解密失败被拒绝', function () {
    // 用 keyA 加密，用 keyB 解密
    [$hexA] = makeBackupEncKey();
    [$hexB] = makeBackupEncKey();
    expect($hexA)->not->toBe($hexB);

    $svc = new BackupService;

    config(['backup.enc_key' => $hexA]);
    $src = sys_get_temp_dir().'/bk_src_'.uniqid().'.sql.gz';
    file_put_contents($src, 'plaintext-payload');
    register_shutdown_function(static fn () => @unlink($src));
    $enc = $svc->encryptBackup($src);
    register_shutdown_function(static fn () => @unlink($enc));

    config(['backup.enc_key' => $hexB]);

    expect(fn () => $svc->decryptBackup($enc, $enc.'.out'))
        ->toThrow(RuntimeException::class, '解密失败');
});
