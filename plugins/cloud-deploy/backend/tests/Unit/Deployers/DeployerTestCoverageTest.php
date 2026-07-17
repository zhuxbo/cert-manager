<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Tests\TestCase;

uses(TestCase::class);

function cloudDeployProviderDirFor(string $class): string
{
    $parts = explode('\\', $class);
    $index = array_search('Deployers', $parts, true);
    expect($index)->not->toBeFalse("Cannot resolve deployer namespace: $class");
    expect(array_key_exists($index + 1, $parts))->toBeTrue("Cannot resolve provider segment: $class");

    return $parts[$index + 1];
}

test('每个注册 deployer 都有本 provider 目录下的 mock 单测覆盖', function () {
    $root = base_path('../plugins/cloud-deploy/backend/tests/Unit/Deployers');
    $missing = [];

    foreach (app(Registry::class)->allDeployers() as $entry) {
        $label = $entry['provider'].'.'.$entry['product'];
        $deployer = app(Registry::class)->resolveDeployer($entry['provider'], $entry['product']);
        $class = $deployer::class;
        $reflection = new ReflectionClass($class);
        $providerDir = $root.'/'.cloudDeployProviderDirFor($class);

        if (! is_dir($providerDir)) {
            $missing[] = "$label => missing directory $providerDir";

            continue;
        }

        $shortName = $reflection->getShortName();
        $matched = false;
        foreach (glob($providerDir.'/*Test.php') ?: [] as $testFile) {
            $content = file_get_contents($testFile);
            if ($content !== false && str_contains($content, $shortName)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            $missing[] = "$label => $shortName not referenced by provider mock tests";
        }
    }

    expect($missing)->toBe([]);
});

test('所有 registry 文件都被 ServiceProvider 接入真实 catalog', function () {
    $registryDir = base_path('../plugins/cloud-deploy/backend/Deployers/registry');
    $files = array_map(
        fn (string $file) => basename($file, '.php'),
        glob($registryDir.'/*.php') ?: [],
    );
    sort($files);

    $providerKeys = array_column(app(Registry::class)->catalog()['providers'], 'key');
    sort($providerKeys);

    expect($providerKeys)->toEqual($files);
});
