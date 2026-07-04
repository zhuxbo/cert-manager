<?php

use Plugins\CloudDeploy\Deployers\Webhook\WebhookDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 把 configSchema 索引为 key => 字段定义，便于断言 secret 标记 */
function webhookConfigByKey(): array
{
    $schema = (new WebhookDeployer)->configSchema();

    return collect($schema)->keyBy('key')->all();
}

test('config.headers 标 secret=true（含 Authorization 鉴权头，admin 列表须打码）', function () {
    $byKey = webhookConfigByKey();

    expect($byKey)->toHaveKey('headers');
    expect($byKey['headers']['secret'] ?? false)->toBeTrue();
});

test('config.webhook_data 标 secret=true（回调 JSON 可含私钥变量/敏感载荷）', function () {
    $byKey = webhookConfigByKey();

    expect($byKey)->toHaveKey('webhook_data');
    expect($byKey['webhook_data']['secret'] ?? false)->toBeTrue();
});

test('config.timeout 不是 secret（非敏感，admin 列表保留）', function () {
    $byKey = webhookConfigByKey();

    expect($byKey)->toHaveKey('timeout');
    expect($byKey['timeout']['secret'] ?? false)->toBeFalse();
});

test('configSchema 仍含全部三键（回归：补 secret 不丢字段）', function () {
    $keys = array_column((new WebhookDeployer)->configSchema(), 'key');

    expect($keys)->toContain('webhook_data')->toContain('headers')->toContain('timeout');
});
