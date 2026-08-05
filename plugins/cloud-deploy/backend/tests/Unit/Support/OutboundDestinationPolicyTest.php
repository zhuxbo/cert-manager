<?php

use Plugins\CloudDeploy\Support\OutboundDestinationException;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Plugins\CloudDeploy\Support\OutboundIpClassifier;
use Plugins\CloudDeploy\Support\SafeHttpClientFactory;
use Tests\TestCase;

uses(TestCase::class);

function cloudDeployOutboundPolicy(array $answers, array $privateTargets = []): OutboundDestinationPolicy
{
    return new OutboundDestinationPolicy(
        resolver: static fn (string $host): array => $answers[$host] ?? [],
        privateTargets: $privateTargets,
    );
}

test('公共域名解析结果被规范化并允许访问', function () {
    $policy = cloudDeployOutboundPolicy(['panel.example.com' => ['93.184.216.34']]);

    $destination = $policy->authorize('samwaf', 'https://PANEL.EXAMPLE.COM./api/');

    expect($destination->host)->toBe('panel.example.com')
        ->and($destination->port)->toBe(443)
        ->and($destination->url)->toBe('https://panel.example.com/api/')
        ->and($destination->addresses)->toBe(['93.184.216.34'])
        ->and($destination->scope)->toBe(OutboundIpClassifier::PUBLIC);
});

test('私网目标默认拒绝，只有 provider host port 精确白名单才允许', function () {
    $policy = cloudDeployOutboundPolicy(['panel.internal' => ['10.20.30.40']]);

    try {
        $policy->authorize('samwaf', 'https://panel.internal:9443/api');
        test()->fail('未配置白名单的私网目标应被拒绝');
    } catch (OutboundDestinationException $e) {
        expect($e->reasonCode())->toBe('private_not_allowed');
    }

    $allowed = cloudDeployOutboundPolicy(
        ['panel.internal' => ['10.20.30.40']],
        privateTargets: ['samwaf@panel.internal:9443'],
    )->authorize('samwaf', 'https://panel.internal:9443/api');

    expect($allowed->scope)->toBe(OutboundIpClassifier::PRIVATE);
});

test('回环和链路本地地址即使写入白名单也始终拒绝', function (string $ip) {
    $policy = cloudDeployOutboundPolicy(
        ['panel.internal' => [$ip]],
        privateTargets: ['samwaf@panel.internal:443'],
    );

    try {
        $policy->authorize('samwaf', 'https://panel.internal/api');
        test()->fail('禁止地址不应被白名单放行');
    } catch (OutboundDestinationException $e) {
        expect($e->reasonCode())->toBe('forbidden_address');
    }
})->with(['127.0.0.1', '169.254.169.254', '::1', 'fe80::1']);

test('同一域名混合解析到公网和私网地址时拒绝', function () {
    $policy = cloudDeployOutboundPolicy([
        'rebinding.example' => ['93.184.216.34', '10.0.0.8'],
    ], privateTargets: ['samwaf@rebinding.example:443']);

    try {
        $policy->authorize('samwaf', 'https://rebinding.example');
        test()->fail('公网与私网混合解析应被拒绝');
    } catch (OutboundDestinationException $e) {
        expect($e->reasonCode())->toBe('mixed_address_scope');
    }
});

test('域名解析失败和 URL 用户信息均按失败关闭处理', function () {
    $policy = cloudDeployOutboundPolicy([]);

    foreach ([
        ['https://missing.example', 'dns_resolution_failed'],
        ['https://user:pass@missing.example', 'userinfo_not_allowed'],
    ] as [$url, $reason]) {
        try {
            $policy->authorize('samwaf', $url);
            test()->fail("目标 {$url} 应被拒绝");
        } catch (OutboundDestinationException $e) {
            expect($e->reasonCode())->toBe($reason);
        }
    }
});

test('厂商官方派生主机必须是完整 DNS 名且不能含 URL 分隔符', function () {
    $policy = cloudDeployOutboundPolicy([
        'bucket.tos-ap-singapore-1.bytepluses.com' => ['93.184.216.34'],
    ]);

    $allowed = $policy->authorizeOfficialHost(
        'byteplus',
        'bucket.tos-ap-singapore-1.bytepluses.com',
    );
    expect($allowed->host)->toBe('bucket.tos-ap-singapore-1.bytepluses.com');

    foreach ([
        'public.example:443/path.tos-ap-singapore-1.bytepluses.com',
        'user@public.example.tos-ap-singapore-1.bytepluses.com',
        'public.example?next=.tos-ap-singapore-1.bytepluses.com',
        'public.example#fragment.tos-ap-singapore-1.bytepluses.com',
    ] as $host) {
        try {
            $policy->authorizeOfficialHost('byteplus', $host);
            test()->fail("含 URL 分隔符的官方派生主机应被拒绝：{$host}");
        } catch (OutboundDestinationException $e) {
            expect($e->reasonCode())->toBe('invalid_official_host');
        }
    }
});

test('安全客户端把连接钉在已审 IP 并移除可覆盖连接目标的 cURL 选项', function () {
    $policy = cloudDeployOutboundPolicy(['panel.example.com' => ['93.184.216.34']]);
    $factory = new SafeHttpClientFactory($policy);

    $client = $factory->forBaseUri('samwaf', 'https://panel.example.com/api/', [
        'verify' => false,
        'headers' => ['X-Test' => 'yes'],
        'allow_redirects' => true,
        'proxy' => 'http://127.0.0.1:8080',
        'curl' => [
            CURLOPT_PROXY => 'http://127.0.0.1:8080',
            CURLOPT_RESOLVE => ['panel.example.com:443:127.0.0.1'],
            CURLOPT_CONNECT_TO => ['panel.example.com:443:127.0.0.1:443'],
            CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
            CURLOPT_TCP_KEEPALIVE => 1,
        ],
    ]);

    $curl = $client->getConfig('curl');
    expect($client->getConfig('allow_redirects'))->toBeFalse()
        ->and((string) $client->getConfig('base_uri'))->toBe('https://panel.example.com/api/')
        ->and($client->getConfig('verify'))->toBeFalse()
        ->and($client->getConfig('proxy'))->toBeNull()
        ->and($client->getConfig('headers')['X-Test'] ?? null)->toBe('yes')
        ->and($curl[CURLOPT_RESOLVE] ?? null)->toBe(['panel.example.com:443:93.184.216.34'])
        ->and($curl[CURLOPT_TCP_KEEPALIVE] ?? null)->toBe(1)
        ->and(array_key_exists(CURLOPT_PROXY, $curl))->toBeFalse()
        ->and(array_key_exists(CURLOPT_CONNECT_TO, $curl))->toBeFalse()
        ->and(array_key_exists(CURLOPT_UNIX_SOCKET_PATH, $curl))->toBeFalse();
});

test('安全客户端使用规范化 URL', function () {
    $policy = cloudDeployOutboundPolicy(['panel.example.com' => ['93.184.216.34']]);
    $factory = new SafeHttpClientFactory($policy);

    $client = $factory->forBaseUri('samwaf', 'https://PANEL.EXAMPLE.COM./api/');

    expect((string) $client->getConfig('base_uri'))->toBe('https://panel.example.com/api/');
});

test('租户可控 URL 的运行时客户端统一经过插件安全工厂', function () {
    $base = dirname(__DIR__, 3);
    $files = [
        'Deployers/Apisix/CertificateDeployer.php',
        'Deployers/Baotapanel/BuildsBaotapanelClient.php',
        'Deployers/Baotapanelgo/BuildsBaotapanelgoClient.php',
        'Deployers/Baotawaf/BuildsBaotawafClient.php',
        'Deployers/Cdnfly/CdnDeployer.php',
        'Deployers/Cpanel/CpanelDeployer.php',
        'Deployers/Dokploy/CertificateDeployer.php',
        'Deployers/Flexcdn/FlexcdnDeployer.php',
        'Deployers/Goedge/GoedgeDeployer.php',
        'Deployers/Kong/CertificateDeployer.php',
        'Deployers/Lecdn/LecdnDeployer.php',
        'Deployers/Nginxproxymanager/CertificateDeployer.php',
        'Deployers/Onepanel/BuildsOnepanelClient.php',
        'Deployers/Proxmoxve/NodeDeployer.php',
        'Deployers/Ratpanel/BuildsRatpanelClient.php',
        'Deployers/Safeline/SafelineDeployer.php',
        'Deployers/Samwaf/SamwafDeployer.php',
        'Deployers/Synologydsm/CertificateDeployer.php',
        'Deployers/Webhook/WebhookDeployer.php',
    ];

    $offenders = [];
    foreach ($files as $file) {
        if (str_contains((string) file_get_contents("$base/$file"), 'new GuzzleClient(')) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([]);
});
