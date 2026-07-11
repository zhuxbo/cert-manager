<?php

use Plugins\CloudDeploy\Jobs\CloudChainBackfillJob;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Jobs\CloudDeployTriggerJob;
use Tests\TestCase;

uses(TestCase::class);

// 升级冻结期 SkipWhenUpgradeFrozen 会 release 这两个编排 Job，release 计入 attempts；
// tries=1 会被第二次 pop 误杀在 handle 之前（见主 suite UpgradeFreezeReleaseAttemptsTest 复现）。
// 守门：保持 tries=5（吸收 release）+ maxExceptions=1（业务异常仍只一次）。
test('cloud-deploy 编排 Job 已配置 tries=5 + maxExceptions=1（吸收升级冻结 release，业务异常仍只一次）', function () {
    foreach ([CloudDeployTriggerJob::class, CloudChainBackfillJob::class] as $class) {
        $defaults = (new ReflectionClass($class))->getDefaultProperties();

        expect($defaults['tries'] ?? null)->toBe(5, "$class 的 tries 应为 5")
            ->and($defaults['maxExceptions'] ?? null)->toBe(1, "$class 的 maxExceptions 应为 1");
    }
});

// CloudDeployJob（G2）：tries 3→5（长轮询超窗改抛 DeployPollPendingException 走重试通道，需更多 attempt
// 覆盖云端异步落地 + 吸收 freeze release）；**不加 maxExceptions**——maxExceptions 会在首个 pending 异常
// 终结重试链（主控裁决）。$timeout=55 < worker --timeout 60、< retry_after 600。
test('CloudDeployJob 配置 tries=5 + 无 maxExceptions + timeout=55（G2 重试通道，SIGALRM 前优雅退出）', function () {
    $defaults = (new ReflectionClass(CloudDeployJob::class))->getDefaultProperties();

    expect($defaults['tries'] ?? null)->toBe(5, 'CloudDeployJob tries 应为 5')
        ->and(array_key_exists('maxExceptions', $defaults) ? $defaults['maxExceptions'] : null)->toBeNull('CloudDeployJob 不得设 maxExceptions')
        ->and($defaults['timeout'] ?? null)->toBe(55, 'CloudDeployJob timeout 应为 55')
        ->and($defaults['timeout'])->toBeLessThan(60, 'timeout 须 < worker --timeout 60');
});
