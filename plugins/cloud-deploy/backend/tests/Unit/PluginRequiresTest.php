<?php

use Tests\TestCase;

uses(TestCase::class);

test('cloud-deploy plugin.json requires 提升到 >=0.6.3', function () {
    $path = base_path('../plugins/cloud-deploy/plugin.json');
    expect(is_file($path))->toBeTrue();

    $json = json_decode((string) file_get_contents($path), true);
    expect($json)->toBeArray();
    expect($json['requires'] ?? null)->toBe('>=0.6.3');
});
