<?php

use App\Services\Upgrade\ReleaseClient;
use Illuminate\Support\Facades\Config;

uses(Tests\TestCase::class);

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

    $reflection = new \ReflectionClass($client);
    $method = $reflection->getMethod('matchChannel');

    // main 通道：不带 -dev 后缀
    expect($method->invoke($client, 'v1.0.0', 'main'))->toBeTrue();
    expect($method->invoke($client, 'v2.1.3', 'main'))->toBeTrue();
    expect($method->invoke($client, 'v1.0.0-dev', 'main'))->toBeFalse();
    expect($method->invoke($client, 'v2.0.0-dev', 'main'))->toBeFalse();
});

test('match channel dev', function () {
    $client = new ReleaseClient;

    $reflection = new \ReflectionClass($client);
    $method = $reflection->getMethod('matchChannel');

    // dev 通道：带 -dev 后缀
    expect($method->invoke($client, 'v1.0.0-dev', 'dev'))->toBeTrue();
    expect($method->invoke($client, 'v2.1.3-dev', 'dev'))->toBeTrue();
    expect($method->invoke($client, 'v1.0.0', 'dev'))->toBeFalse();
    expect($method->invoke($client, 'v2.0.0', 'dev'))->toBeFalse();
});

test('normalize release', function () {
    $client = new ReleaseClient;

    $reflection = new \ReflectionClass($client);
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

    $reflection = new \ReflectionClass($client);
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

test('resolveExecutablePath 对不存在命令返回 null', function () {
    $client = new ReleaseClient;

    $reflection = new \ReflectionClass($client);
    $method = $reflection->getMethod('resolveExecutablePath');

    $result = $method->invoke($client, 'cmd-not-exists-'.uniqid());

    expect($result)->toBeNull();
});
