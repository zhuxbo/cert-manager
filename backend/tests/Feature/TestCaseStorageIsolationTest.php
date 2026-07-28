<?php

use Illuminate\Support\Facades\Storage;

// storage 隔离对并行与单进程都必须生效：单进程不隔离时，造/删真实磁盘文件的测试会
// 改开发环境的 storage（已踩：支付证书被测试删掉又留下假证书，见 skills/backend/core.md）。

test('storage 隔离到本次运行专属目录（并行与单进程都生效）', function () {
    $token = getenv('TEST_TOKEN');
    $expectedToken = $token === false || $token === '' ? 'single' : $token;

    expect(storage_path())->toContain('framework/testing/worker-'.$expectedToken)
        ->and(storage_path())->not->toBe(base_path('storage'))
        ->and(is_dir(storage_path('framework')))->toBeTrue();
});

test('Storage 门面 local/public disk 与 storage_path 指向同一隔离目录', function () {
    expect(Storage::disk('local')->path(''))->toContain('framework/testing/worker-')
        ->and(Storage::disk('public')->path(''))->toContain('framework/testing/worker-');
});

test('测试写文件落在隔离目录内，不碰真实 storage 根', function () {
    $relative = 'isolation-probe-'.getmypid().'.txt';
    file_put_contents(storage_path($relative), 'probe');

    try {
        expect(is_file(storage_path($relative)))->toBeTrue()
            ->and(is_file(base_path('storage/'.$relative)))->toBeFalse();
    } finally {
        // 隔离若失效，探针会落到真实 storage 根：断言抛异常也必须清掉，别留垃圾
        @unlink(storage_path($relative));
        @unlink(base_path('storage/'.$relative));
    }
});
