<?php

use App\Services\Upgrade\ReleaseClient;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Config::set('upgrade.source.provider', 'github');
    Config::set('upgrade.source.github', [
        'owner' => 'test-owner',
        'repo' => 'test-repo',
        'api_base' => 'https://api.github.com',
    ]);
    Config::set('version.channel', 'main');
});

test('match channel main', function () {
    $client = new ReleaseClient;

    $reflection = new ReflectionClass($client);
    $method = $reflection->getMethod('matchChannel');

    // main 通道：不带 -dev 后缀
    expect($method->invoke($client, 'v1.0.0', 'main'))->toBeTrue();
    expect($method->invoke($client, 'v2.1.3', 'main'))->toBeTrue();
    expect($method->invoke($client, 'v1.0.0-dev', 'main'))->toBeFalse();
    expect($method->invoke($client, 'v2.0.0-dev', 'main'))->toBeFalse();
});

test('match channel dev', function () {
    $client = new ReleaseClient;

    $reflection = new ReflectionClass($client);
    $method = $reflection->getMethod('matchChannel');

    // dev 通道：带 -dev 后缀
    expect($method->invoke($client, 'v1.0.0-dev', 'dev'))->toBeTrue();
    expect($method->invoke($client, 'v2.1.3-dev', 'dev'))->toBeTrue();
    expect($method->invoke($client, 'v1.0.0', 'dev'))->toBeFalse();
    expect($method->invoke($client, 'v2.0.0', 'dev'))->toBeFalse();
});

test('normalize release', function () {
    $client = new ReleaseClient;

    $reflection = new ReflectionClass($client);
    $method = $reflection->getMethod('normalizeRelease');

    $rawRelease = [
        'tag_name' => 'v1.2.3',
        'name' => 'Release 1.2.3',
        'body' => 'Release notes',
        'prerelease' => false,
        'created_at' => '2025-01-15T10:00:00Z',
        'published_at' => '2025-01-15T10:30:00Z',
        'assets' => [
            [
                'name' => 'ssl-manager-full-1.2.3.zip',
                'size' => 1024000,
                'browser_download_url' => 'https://example.com/full.zip',
            ],
            [
                'name' => 'ssl-manager-upgrade-1.2.3.zip',
                'size' => 512000,
                'browser_download_url' => 'https://example.com/upgrade.zip',
            ],
        ],
    ];

    $result = $method->invoke($client, $rawRelease);

    expect($result['version'])->toBe('1.2.3');
    expect($result['tag_name'])->toBe('v1.2.3');
    expect($result['name'])->toBe('Release 1.2.3');
    expect($result['body'])->toBe('Release notes');
    expect($result['prerelease'])->toBeFalse();
    expect($result['assets'])->toHaveCount(2);
});

test('normalize release with v prefix', function () {
    $client = new ReleaseClient;

    $reflection = new ReflectionClass($client);
    $method = $reflection->getMethod('normalizeRelease');

    $releases = [
        ['tag_name' => 'v1.0.0', 'expected' => '1.0.0'],
        ['tag_name' => 'V2.0.0', 'expected' => '2.0.0'],
        ['tag_name' => '3.0.0', 'expected' => '3.0.0'],
    ];

    foreach ($releases as $testCase) {
        $result = $method->invoke($client, ['tag_name' => $testCase['tag_name']]);
        expect($result['version'])->toBe($testCase['expected']);
    }
});

test('find upgrade package url', function () {
    $client = new ReleaseClient;

    $release = [
        'assets' => [
            ['name' => 'ssl-manager-full-1.0.0.zip', 'browser_download_url' => 'https://example.com/full.zip'],
            ['name' => 'ssl-manager-upgrade-1.0.0.zip', 'browser_download_url' => 'https://example.com/upgrade.zip'],
            ['name' => 'ssl-manager-script-1.0.0.zip', 'browser_download_url' => 'https://example.com/script.zip'],
        ],
    ];

    $url = $client->findUpgradePackageUrl($release);

    expect($url)->toBe('https://example.com/upgrade.zip');
});

test('find upgrade package url not found', function () {
    $client = new ReleaseClient;

    $release = [
        'assets' => [
            ['name' => 'ssl-manager-full-1.0.0.zip', 'browser_download_url' => 'https://example.com/full.zip'],
        ],
    ];

    $url = $client->findUpgradePackageUrl($release);

    expect($url)->toBeNull();
});

test('find full package url', function () {
    $client = new ReleaseClient;

    $release = [
        'assets' => [
            ['name' => 'ssl-manager-full-1.0.0.zip', 'browser_download_url' => 'https://example.com/full.zip'],
            ['name' => 'ssl-manager-upgrade-1.0.0.zip', 'browser_download_url' => 'https://example.com/upgrade.zip'],
        ],
    ];

    $url = $client->findFullPackageUrl($release);

    expect($url)->toBe('https://example.com/full.zip');
});

test('find full package url not found', function () {
    $client = new ReleaseClient;

    $release = [
        'assets' => [
            ['name' => 'ssl-manager-upgrade-1.0.0.zip', 'browser_download_url' => 'https://example.com/upgrade.zip'],
        ],
    ];

    $url = $client->findFullPackageUrl($release);

    expect($url)->toBeNull();
});

test('find package url empty assets', function () {
    $client = new ReleaseClient;

    $release = ['assets' => []];

    expect($client->findUpgradePackageUrl($release))->toBeNull();
    expect($client->findFullPackageUrl($release))->toBeNull();
});

test('find package url no assets key', function () {
    $client = new ReleaseClient;

    $release = [];

    expect($client->findUpgradePackageUrl($release))->toBeNull();
    expect($client->findFullPackageUrl($release))->toBeNull();
});

/**
 * 构造一个内部 baseUrl 已设、fetchReleases 被 mock 的 ReleaseClient
 */
function makeClientWithReleases(array $releases): ReleaseClient
{
    $client = Mockery::mock(ReleaseClient::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $client->shouldReceive('fetchReleases')->andReturn($releases);

    // 直接设置 protected baseUrl，跳过构造时读取 version.json 的 release_url
    $ref = new ReflectionClass(ReleaseClient::class);
    $prop = $ref->getProperty('baseUrl');
    $prop->setAccessible(true);
    $prop->setValue($client, 'https://example.com/releases');

    return $client;
}

test('get latest release prefers higher beta number', function () {
    // 旧实现把 -beta.N 后缀剥光后两者相等，"最新"取决于循环顺序，beta.10 检测不出
    $releases = [
        ['tag_name' => 'v0.5.2-beta.9', 'published_at' => '2026-05-27T10:00:00Z'],
        ['tag_name' => 'v0.5.2-beta.10', 'published_at' => '2026-05-28T10:00:00Z'],
    ];

    $client = makeClientWithReleases($releases);
    $latest = $client->getLatestRelease('dev');

    expect($latest)->not->toBeNull();
    expect($latest['version'])->toBe('0.5.2-beta.10');
});

test('get latest release picks highest across mixed prereleases', function () {
    // 混合 alpha/beta/rc，应选 rc.1（rc > beta > alpha）
    $releases = [
        ['tag_name' => 'v1.0.0-alpha.5'],
        ['tag_name' => 'v1.0.0-beta.3'],
        ['tag_name' => 'v1.0.0-beta.10'],
        ['tag_name' => 'v1.0.0-rc.1'],
    ];

    $client = makeClientWithReleases($releases);
    $latest = $client->getLatestRelease('dev');

    expect($latest['version'])->toBe('1.0.0-rc.1');
});

test('get latest release main channel still works after fix', function () {
    // main 通道不受影响：选最高的正式版
    $releases = [
        ['tag_name' => 'v1.0.0'],
        ['tag_name' => 'v1.0.1'],
        ['tag_name' => 'v1.0.0-beta.99'],  // 被 channel 过滤
    ];

    $client = makeClientWithReleases($releases);
    $latest = $client->getLatestRelease('main');

    expect($latest['version'])->toBe('1.0.1');
});

test('get latest release case insensitive across mixed case prereleases (M2 fix)', function () {
    // M2 修复：getLatestRelease 内部直接调 version_compare 也加了 strtolower
    // 旧实现：Beta（大写）被映射为 #，"最新"判断会错把 Beta.9 当成 > beta.10
    $releases = [
        ['tag_name' => 'v0.5.2-Beta.9'],
        ['tag_name' => 'v0.5.2-beta.10'],
    ];

    $client = makeClientWithReleases($releases);
    $latest = $client->getLatestRelease('dev');

    expect($latest['version'])->toBe('0.5.2-beta.10');
});
