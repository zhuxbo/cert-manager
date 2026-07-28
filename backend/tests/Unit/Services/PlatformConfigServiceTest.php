<?php

use App\Services\PlatformConfigService;

/**
 * 品牌清洗算法与前端 frontend/shared/src/utils/brandOptions.ts 为对称副本，
 * 双端共享同一夹具（tests/Fixtures/brand-normalize-cases.json）锁定输出等价；
 * 改任一侧语义必须同步夹具与另一侧实现（反模式 4 配套门禁）。
 */
test('brandOptions 与共享夹具输出一致', function () {
    $cases = json_decode(
        file_get_contents(__DIR__.'/../../Fixtures/brand-normalize-cases.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    expect($cases)->toBeArray()->not->toBeEmpty();

    $method = new ReflectionMethod(PlatformConfigService::class, 'brandOptions');

    foreach ($cases as $case) {
        $actual = $method->invoke(new PlatformConfigService, $case['input']);
        expect($actual)->toBe($case['expected'], "夹具用例失败: {$case['name']}");
    }
});

test('activeBrandOptions 按活动设置顺序解析并过滤重复和词典外品牌', function () {
    $method = new ReflectionMethod(PlatformConfigService::class, 'activeBrandOptions');
    $allBrands = [
        ['label' => 'Certum', 'value' => 'certum'],
        ['label' => 'DigiCert', 'value' => 'digicert'],
        ['label' => '锐安信', 'value' => 'ssltrus'],
    ];

    expect($method->invoke(
        new PlatformConfigService,
        [' ssltrus ', 'CERTUM', 'ssltrus', 'unknown', null],
        $allBrands,
    ))->toBe([
        ['label' => '锐安信', 'value' => 'ssltrus'],
        ['label' => 'Certum', 'value' => 'certum'],
    ]);
});
