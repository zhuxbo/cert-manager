<?php

use App\Services\Order\Utils\DomainUtil;
use Tests\Support\PublicSuffixListFixture;
use Tests\TestCase;

uses(TestCase::class);

// DomainUtilTest 依赖仓内 PSL 快照。夹具一旦被截断或换成别的东西，DomainUtil 会静默回落到
// 内置的极简后缀表（无任何多级后缀），DomainUtilTest 只报“两字符串不相等”。这里把根因单独测
// 出来，失败信息直接说清是夹具的问题。

test('公共后缀表夹具合格', function () {
    expect(PublicSuffixListFixture::validationError())->toBeNull();
});

test('隔离 storage 已灌入公共后缀表且 mtime 恒为当下', function () {
    $cache = PublicSuffixListFixture::cachePath(storage_path());

    expect($cache)->toBeFile()
        ->and(hash_file('xxh128', $cache))->toBe(hash_file('xxh128', PublicSuffixListFixture::path()))
        // seed() 每次都 touch，DomainUtil 的 TTL 被改多短都判"未过期"。断"就是刚刚"而不是
        // 断"小于 30 天"——后者只是把 DomainUtil 的常量再抄一遍，且恒真、测不出任何东西
        ->and(time() - filemtime($cache))->toBeLessThanOrEqual(5);
});

test('DomainUtil 读的确实是被灌入的这份表', function () {
    // 夹具里的缓存路径是 DomainUtil::loadRules() 的手抄副本。抄错或上游改名时，联网 CI 下
    // DomainUtil 会自己把真表抓回来，一切照常全绿，离线确定性静默失效、又退回“上游改表就飘红”。
    // 故往缓存里插一条现实中不存在的探针后缀：只有 DomainUtil 真读这份文件，解析结果才会变。
    $cache = PublicSuffixListFixture::cachePath(storage_path());
    $original = file_get_contents($cache);
    $marker = "// ===BEGIN ICANN DOMAINS===\n";
    $probed = str_replace($marker, $marker."probe.ssl-manager-fixture\n", $original);

    // 探针没插进去（PSL 段落标记变了）说明这个测试已失去意义，直接红而不是假绿
    expect($probed)->not->toBe($original);

    $rules = new ReflectionProperty(DomainUtil::class, 'rules');

    try {
        file_put_contents($cache, $probed);
        $rules->setValue(null, null);

        // 命中探针规则时公共后缀是 probe.ssl-manager-fixture，根域名多一级；
        // 读到别处的真表则只会命中默认 '*' 规则，得到 probe.ssl-manager-fixture
        expect(DomainUtil::getRootDomain('a.b.probe.ssl-manager-fixture'))
            ->toBe('b.probe.ssl-manager-fixture');
    } finally {
        file_put_contents($cache, $original);
        $rules->setValue(null, null);
    }
});
