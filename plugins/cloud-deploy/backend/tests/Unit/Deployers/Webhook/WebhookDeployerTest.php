<?php

use Plugins\CloudDeploy\Deployers\Webhook\WebhookApiException;
use Plugins\CloudDeploy\Deployers\Webhook\WebhookClient;
use Plugins\CloudDeploy\Deployers\Webhook\WebhookDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试用叶子证书（CN=example.com，SAN=example.com,www.example.com）——验证 ${...COMMONNAME} /
 * ${...SUBJECTALTNAMES} 替换 + 默认数据 name 字段。
 */
const WEBHOOK_LEAF_CERT = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIC2jCCAcKgAwIBAgIJAN9su0DuKwuhMA0GCSqGSIb3DQEBCwUAMBYxFDASBgNV
BAMMC2V4YW1wbGUuY29tMB4XDTI2MDYyNzIxMTAzMloXDTM2MDYyNDIxMTAzMlow
FjEUMBIGA1UEAwwLZXhhbXBsZS5jb20wggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAw
ggEKAoIBAQC0R4y8I2YLygUraatZ1IHBWuK4kGDl+U+01sC4Qp4u4Q0CkrLsgrLc
FAS3CqsqskSfGjZ5HyNkUnpQ8p8NoMLqpGsYt0HH7C0lVA0K96qrGmYyRjwUN2G1
M9m7BTetYP39ARsN773aC3gSqTI/r64BpSU1spL1waRQIqRCuY7OZC/zc9UDtFsD
uQnyMR5B+3vmw76/q5SusI8OEMqDuASnkMH9qXaNlthvv/42lLE2JRxVJWcngJfp
GrHmnKd5u2rWR9UxzQqIbAUi4G6ysY63cjS6YqXyBbZYvt9njEEUP9WPdGImiItO
rUIBXXh7GoFcEgNjyaoovwSXxvT7t2+ZAgMBAAGjKzApMCcGA1UdEQQgMB6CC2V4
YW1wbGUuY29tgg93d3cuZXhhbXBsZS5jb20wDQYJKoZIhvcNAQELBQADggEBABBH
nwDweqxYSw1BAdmcS6FTShiSx9BLklv2RMbrzk2Y78fXu7YFGNuA2Hk+oLBGhM1m
8nIIiPW7AtNETvL83DiJI1C5BY6eY5wzuNFUuOTnTP/9RkU2zaNByILVwRNrObLt
A9DX1TnQ9WIUYtqCTUfX1sTL+RJBN15TobO2208eVOyawDlf8x83n+UqQqAyzvsI
ekYkKeljvzBQ6aKklt5w9z8EbkWn0I+UL+JpITAydbxAvzq4f9kS9YpQVpvtUeOd
8sN756DBbTIXEE7Khh1sr77U+zEnD6Nys+hyIHz/7zYn80RHjS3A6dskmabEuiy2
xIgmdvTJjWw6aUyT6Fk=
-----END CERTIFICATE-----
PEM;

/** 测试子类：override makeClient（http kind）注入 mock WebhookClient。 */
function webhookDeployerWith(callable $clientFactory): WebhookDeployer
{
    return new class($clientFactory) extends WebhookDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function webhookCertRef(): array
{
    return ['cert' => WEBHOOK_LEAF_CERT, 'key' => 'KEYPEM', 'chain' => 'INTERMEDIAPEM'];
}

test('Webhook 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new WebhookDeployer;
    expect($deployer->provider())->toBe('webhook');
    expect($deployer->product())->toBe('webhook');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('webhook_data')->toContain('headers')->toContain('timeout');
});

test('默认数据（未配置 data）：POST json，body {name(SAN;分隔), cert(完整链), privkey}', function () {
    $captured = null;
    $client = Mockery::mock(WebhookClient::class);
    $client->shouldReceive('send')->once()->andReturnUsing(function (string $method, string $url, array $headers, string $contentType, mixed $data, int $timeout) use (&$captured) {
        $captured = compact('method', 'url', 'headers', 'contentType', 'data', 'timeout');
    });

    $deployer = webhookDeployerWith(fn () => $client);
    $deployer->bind(webhookCertRef(), ['url' => 'https://hook.example.com/cb'], []);

    expect($captured['method'])->toBe('POST');
    expect($captured['contentType'])->toBe(WebhookClient::CONTENT_TYPE_JSON);
    expect($captured['url'])->toBe('https://hook.example.com/cb');
    expect($captured['data']['name'])->toBe('example.com;www.example.com');
    expect($captured['data']['cert'])->toContain('-----BEGIN CERTIFICATE-----')->toContain('INTERMEDIAPEM');
    expect($captured['data']['privkey'])->toBe('KEYPEM');
    expect($captured['timeout'])->toBe(30);
});

test('自定义 JSON data + 变量替换（新版变量，json 保留结构）', function () {
    $captured = null;
    $client = Mockery::mock(WebhookClient::class);
    $client->shouldReceive('send')->once()->andReturnUsing(function ($m, $u, $h, $ct, $data, $t) use (&$captured) {
        $captured = $data;
    });

    $data = json_encode([
        'cn' => '${CERTIMATE_DEPLOYER_COMMONNAME}',
        'san' => '${CERTIMATE_DEPLOYER_SUBJECTALTNAMES}',
        'fullchain' => '${CERTIMATE_DEPLOYER_CERTIFICATE}',
        'server' => '${CERTIMATE_DEPLOYER_CERTIFICATE_SERVER}',
        'inter' => '${CERTIMATE_DEPLOYER_CERTIFICATE_INTERMEDIA}',
        'pk' => '${CERTIMATE_DEPLOYER_PRIVATEKEY}',
        'nested' => ['legacy' => '${CERTIFICATE}'],
    ]);

    $deployer = webhookDeployerWith(fn () => $client);
    $deployer->bind(webhookCertRef(), ['url' => 'https://h/cb', 'data' => $data], []);

    expect($captured['cn'])->toBe('example.com');
    expect($captured['san'])->toBe('example.com;www.example.com');
    expect($captured['fullchain'])->toContain('INTERMEDIAPEM');
    expect($captured['server'])->not->toContain('INTERMEDIAPEM');
    expect($captured['inter'])->toBe('INTERMEDIAPEM');
    expect($captured['pk'])->toBe('KEYPEM');
    expect($captured['nested']['legacy'])->toContain('-----BEGIN CERTIFICATE-----'); // 旧版 ${CERTIFICATE} 也替换
});

test('form 内容类型：嵌套结构摊平为 map<string,string>', function () {
    $captured = null;
    $client = Mockery::mock(WebhookClient::class);
    $client->shouldReceive('send')->once()->andReturnUsing(function ($m, $u, $h, $ct, $data, $t) use (&$captured) {
        $captured = compact('ct', 'data');
    });

    $deployer = webhookDeployerWith(fn () => $client);
    $deployer->bind(webhookCertRef(), [
        'url' => 'https://h/cb',
        'method' => 'PUT',
        'headers' => 'Content-Type: application/x-www-form-urlencoded',
        'data' => json_encode(['cert' => '${CERTIFICATE}', 'meta' => ['k' => 'v']]),
    ], []);

    expect($captured['ct'])->toBe(WebhookClient::CONTENT_TYPE_FORM);
    expect($captured['data']['cert'])->toContain('-----BEGIN CERTIFICATE-----');
    expect($captured['data']['meta'])->toBe('{"k":"v"}'); // 嵌套 JSON 序列化为字符串
});

test('GET：数据走查询参数 + URL path 的 ${...COMMONNAME} 被替换转义', function () {
    $captured = null;
    $client = Mockery::mock(WebhookClient::class);
    $client->shouldReceive('send')->once()->andReturnUsing(function ($m, $u, $h, $ct, $data, $t) use (&$captured) {
        $captured = compact('m', 'u', 'data');
    });

    $deployer = webhookDeployerWith(fn () => $client);
    $deployer->bind(webhookCertRef(), [
        'url' => 'https://h/cb/${CERTIMATE_DEPLOYER_COMMONNAME}',
        'method' => 'GET',
        'data' => json_encode(['domain' => '${CERTIMATE_DEPLOYER_COMMONNAME}']),
    ], []);

    expect($captured['m'])->toBe('GET');
    expect($captured['u'])->toBe('https://h/cb/example.com');
    expect($captured['data'])->toBe(['domain' => 'example.com']);
});

test('headers 合并：config.headers 覆盖 credentials.headers 同名键 + timeout 从 config', function () {
    $captured = null;
    $client = Mockery::mock(WebhookClient::class);
    $client->shouldReceive('send')->once()->andReturnUsing(function ($m, $u, $h, $ct, $data, $t) use (&$captured) {
        $captured = compact('h', 't');
    });

    $deployer = webhookDeployerWith(fn () => $client);
    $deployer->bind(webhookCertRef(), [
        'url' => 'https://h/cb',
        'headers' => "X-Token: from-cred\nX-Keep: yes",
    ], [
        'headers' => 'X-Token: from-config',
        'timeout' => '15',
    ]);

    expect($captured['h']['X-Token'])->toBe('from-config'); // config 覆盖
    expect($captured['h']['X-Keep'])->toBe('yes');
    expect($captured['t'])->toBe(15);
});

test('缺 url 抛业务错误', function () {
    $deployer = webhookDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(webhookCertRef(), [], []))
        ->toThrow(RuntimeException::class, 'url');
});

test('不支持的谓词抛业务错误', function () {
    $deployer = webhookDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(webhookCertRef(), ['url' => 'https://h', 'method' => 'TRACE'], []))
        ->toThrow(RuntimeException::class, '不支持的 Webhook 请求谓词');
});

test('不支持的内容类型抛业务错误', function () {
    $deployer = webhookDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(webhookCertRef(), [
        'url' => 'https://h', 'headers' => 'Content-Type: application/xml',
    ], []))->toThrow(RuntimeException::class, '不支持的 Webhook 内容类型');
});

test('bind 遇 WebhookApiException 时脱敏重抛（含状态码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(WebhookClient::class);
    $client->shouldReceive('send')->andThrow(new WebhookApiException('500', 'Webhook 回调返回 HTTP 500'));

    $deployer = webhookDeployerWith(fn () => $client);
    try {
        $deployer->bind(webhookCertRef(), [
            'url' => 'https://h/cb',
            'headers' => 'Authorization: Bearer SECRET-LEAK-123',
        ], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('500');
        expect($e->getMessage())->not->toContain('SECRET-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('SECRET-LEAK-123');
    }
});
