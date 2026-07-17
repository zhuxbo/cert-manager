<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Tests\TestCase;

uses(TestCase::class);

function cloudDeployCertimateFixture(): array
{
    $json = file_get_contents(dirname(__DIR__, 2).'/Fixtures/certimate-deployer-catalog.json');
    expect($json)->not->toBeFalse('Certimate fixture should exist');

    $fixture = json_decode((string) $json, true);
    expect($fixture)->toBeArray('Certimate fixture should be valid JSON');

    return $fixture;
}

function cloudDeploySupportedCoreSlugs(array $fixture): array
{
    $unsupported = $fixture['unsupported'] ?? [];
    $slugs = array_values(array_diff($fixture['core_dirs'], $unsupported));
    sort($slugs);

    return $slugs;
}

function cloudDeployPluginSlug(array $fixture, string $provider, string $product): string
{
    $key = "$provider.$product";
    if (isset($fixture['plugin_slug_aliases'][$key])) {
        return $fixture['plugin_slug_aliases'][$key];
    }

    $provider = $fixture['provider_aliases'][$provider] ?? $provider;

    return "$provider-$product";
}

function cloudDeployNormalizeSourceSlugs(array $values, array $aliases, array $unsupported): array
{
    $normalized = [];
    foreach ($values as $value) {
        $slug = $aliases[$value] ?? $value;
        if (in_array($slug, $unsupported, true)) {
            continue;
        }
        $normalized[] = $slug;
    }
    sort($normalized);

    return array_values(array_unique($normalized));
}

test('插件注册端点与 Certimate core 部署端点对齐（排除 ssh/ftp/local）', function () {
    $fixture = cloudDeployCertimateFixture();
    $expected = cloudDeploySupportedCoreSlugs($fixture);

    $actual = [];
    foreach (app(Registry::class)->allDeployers() as $deployer) {
        $actual[] = cloudDeployPluginSlug($fixture, $deployer['provider'], $deployer['product']);
    }
    sort($actual);

    $missing = array_values(array_diff($expected, $actual));
    $extra = array_values(array_diff($actual, $expected));

    expect($missing)->toBe([], 'cloud-deploy missing Certimate slugs: '.implode(', ', $missing));
    expect($extra)->toBe([], 'cloud-deploy has slugs not in Certimate fixture: '.implode(', ', $extra));
    expect($actual)->toEqual($expected);
});

test('Certimate provider.go 与 sp 文件的命名漂移都由显式 alias 覆盖', function () {
    $fixture = cloudDeployCertimateFixture();
    $expected = cloudDeploySupportedCoreSlugs($fixture);
    $unsupported = $fixture['unsupported'];

    foreach ([
        'provider_go' => $fixture['deployment_provider_values'],
        'sp_files' => $fixture['sp_files'],
    ] as $source => $values) {
        $aliases = $fixture['source_slug_aliases'][$source] ?? [];

        foreach ($aliases as $from => $to) {
            expect(in_array($from, $values, true))->toBeTrue("$source alias source not found: $from");
            expect(in_array($to, $fixture['core_dirs'], true))->toBeTrue("$source alias target not in core dirs: $to");
        }

        $normalized = cloudDeployNormalizeSourceSlugs($values, $aliases, $unsupported);
        $missing = array_values(array_diff($expected, $normalized));
        $extra = array_values(array_diff($normalized, $expected));

        expect($missing)->toBe([], "$source missing normalized core slugs: ".implode(', ', $missing));
        expect($extra)->toBe([], "$source has unknown normalized slugs: ".implode(', ', $extra));
    }
});

test('不实现清单只允许 ssh ftp local，且插件未注册这些端点', function () {
    $fixture = cloudDeployCertimateFixture();
    $unsupported = $fixture['unsupported'];
    sort($unsupported);

    expect($unsupported)->toBe(['ftp', 'local', 'ssh']);

    $actual = [];
    foreach (app(Registry::class)->allDeployers() as $deployer) {
        $actual[] = cloudDeployPluginSlug($fixture, $deployer['provider'], $deployer['product']);
    }

    expect(array_values(array_intersect($actual, $unsupported)))->toBe([]);
});
