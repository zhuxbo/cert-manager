<?php

use Plugins\CloudDeploy\Jobs\CloudChainBackfillJob;
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
