<?php

test('并行 worker storage 预创建 Laravel framework 运行目录', function () {
    $token = getenv('TEST_TOKEN');
    if ($token === false || $token === '') {
        $this->markTestSkipped('仅验证并行测试 worker storage 隔离');
    }

    expect(is_dir(storage_path('framework')))->toBeTrue();
});
