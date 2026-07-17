<?php

use App\Models\Admin;
use App\Models\User;
use App\Services\Notification\Builders\SystemAlertNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // 避开 site 设置真实查表（Unit 不走 RefreshDatabase）
    Cache::put('setting:group_name:site', ['name' => 'SSL证书管理系统'], 3600);
});

afterEach(function () {
    Mockery::close();
});

/**
 * 构造一个 details 只含 admin_email 的最小 intent，附加自定义 context。
 */
function buildSystemAlert(array $context, ?Admin $admin = null): NotificationPayload
{
    $admin ??= (function () {
        $m = Mockery::mock(Admin::class)->makePartial();
        $m->shouldReceive('getAttribute')->with('email')->andReturn('fallback@test.local');

        return $m;
    })();

    return (new SystemAlertNotificationBuilder)->build(
        new NotificationIntent('system_alert', 'admin', 1, $context),
        $admin
    );
}

test('接收者非 Admin 时抛出异常', function () {
    $user = Mockery::mock(User::class)->makePartial();

    (new SystemAlertNotificationBuilder)->build(
        new NotificationIntent('system_alert', 'admin', 1, ['admin_email' => 'a@b.c']),
        $user
    );
})->throws(RuntimeException::class, '通知接收者必须为管理员');

test('admin_email 与 admin->email 都为空时抛出异常', function () {
    $admin = Mockery::mock(Admin::class)->makePartial();
    $admin->shouldReceive('getAttribute')->with('email')->andReturn(null);

    (new SystemAlertNotificationBuilder)->build(
        new NotificationIntent('system_alert', 'admin', 1, []),
        $admin
    );
})->throws(RuntimeException::class, '管理员邮箱为空');

test('顶层白名单：只取 category/title/message/details/email，透传 subject/_meta', function () {
    $payload = buildSystemAlert([
        'admin_email' => 'ops@test.local',
        'category' => 'ca_credentials',
        'title' => '上游 CA 凭证异常',
        'message' => 'Http status code 401',
        'details' => ['probe' => 'get-products'],
        // 误塞的额外顶层键不应进入 data
        'secret_top' => 'should-not-leak',
    ]);

    expect($payload)->toBeInstanceOf(NotificationPayload::class)
        ->and($payload->data['category'])->toBe('ca_credentials')
        ->and($payload->data['title'])->toBe('上游 CA 凭证异常')
        ->and($payload->data['message'])->toBe('Http status code 401')
        ->and($payload->data['details'])->toBe(['probe' => 'get-products'])
        ->and($payload->data['email'])->toBe('ops@test.local')
        ->and($payload->data['_meta']['is_html'])->toBeTrue()
        ->and($payload->data['_meta']['subject'])->toContain('运维告警')
        ->and($payload->data)->not->toHaveKey('secret_top')
        ->and(json_encode($payload->data))->not->toContain('should-not-leak')
        ->and($payload->transient)->toBe([]);
});

// ① 敏感键 denylist：键名命中 → 值掩码 '***'、键保留
test('① 敏感键 denylist：token / apiclient_secret 值被掩码，键保留', function () {
    $payload = buildSystemAlert([
        'admin_email' => 'ops@test.local',
        'details' => [
            'token' => 'abc123',
            'apiclient_secret' => 'xyz789',
            'ca_password' => 'p@ss',
            'private_key' => 'MIIEv...',
            'hmac' => 'deadbeef',
            'probe' => 'get-products', // 无害键正常保留
        ],
    ]);

    $details = $payload->data['details'];
    expect($details)->toHaveKey('token')
        ->and($details['token'])->toBe('***')
        ->and($details['apiclient_secret'])->toBe('***')
        ->and($details['ca_password'])->toBe('***')
        ->and($details['private_key'])->toBe('***')
        ->and($details['hmac'])->toBe('***')
        // 无害键原样保留
        ->and($details['probe'])->toBe('get-products')
        // 原始敏感值不出现在任何位置
        ->and(json_encode($details))->not->toContain('abc123')
        ->and(json_encode($details))->not->toContain('xyz789');
});

// ①-r3 denylist 增补：authorization / bearer / sign
test('①-r3 denylist 增补：authorization / bearer / sign 系键名被掩码', function () {
    $payload = buildSystemAlert([
        'admin_email' => 'ops@test.local',
        'details' => [
            'authorization' => 'Basic dXNlcjpwYXNz',
            'x_bearer' => 'raw-bearer-value',
            'sign' => 'd41d8cd98f00b204',
            'signature' => 'sig-value',
            'probe' => 'ok',
        ],
    ]);

    $details = $payload->data['details'];
    expect($details['authorization'])->toBe('***')
        ->and($details['x_bearer'])->toBe('***')
        ->and($details['sign'])->toBe('***')
        ->and($details['signature'])->toBe('***')
        ->and($details['probe'])->toBe('ok')
        ->and(json_encode($details))->not->toContain('dXNlcjpwYXNz')
        ->and(json_encode($details))->not->toContain('raw-bearer-value');
});

// ②-r3 JWT 值启发式：良性键名 + eyJ 开头长串 → 整值掩码；短串不误伤
test('②-r3 JWT 值启发式：eyJ 开头长串整值掩码，短 eyJ 串不误伤', function () {
    $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NSJ9.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9P';
    $payload = buildSystemAlert([
        'admin_email' => 'ops@test.local',
        'details' => [
            'upstream_body' => $jwt,
            'note' => 'eyJshort',
        ],
    ]);

    $details = $payload->data['details'];
    expect($details['upstream_body'])->toBe('***')
        ->and(json_encode($details))->not->toContain('eyJhbGciOi')
        // 阈值以下的 eyJ 前缀短串保持原样（不做通用 base64 检测，防误伤）
        ->and($details['note'])->toBe('eyJshort');
});

// ② PEM 值掩码：无害键名 + 值含 PEM 头 → 整值 '***'
test('② PEM 值掩码：无害键名但值含 -----BEGIN / PRIVATE KEY → 整值掩码', function () {
    $payload = buildSystemAlert([
        'admin_email' => 'ops@test.local',
        'details' => [
            'chain' => "-----BEGIN CERTIFICATE-----\nMIIByADE...\n-----END CERTIFICATE-----",
            'blob' => 'prefix ... PRIVATE KEY ... suffix',
        ],
    ]);

    $details = $payload->data['details'];
    expect($details['chain'])->toBe('***')
        ->and($details['blob'])->toBe('***')
        ->and(json_encode($details))->not->toContain('BEGIN CERTIFICATE')
        ->and(json_encode($details))->not->toContain('PRIVATE KEY');
});

// ③ 标量化：值为 array/object → '[filtered:non-scalar]'
test('③ 标量化：array / object 值被替换为占位符', function () {
    $obj = (object) ['a' => 1];
    $payload = buildSystemAlert([
        'admin_email' => 'ops@test.local',
        'details' => [
            'nested' => ['a' => 1, 'b' => 2],
            'obj' => $obj,
            'ok' => 'scalar-value',
        ],
    ]);

    $details = $payload->data['details'];
    expect($details['nested'])->toBe('[filtered:non-scalar]')
        ->and($details['obj'])->toBe('[filtered:non-scalar]')
        ->and($details['ok'])->toBe('scalar-value');
});

// ④ 值长度上限：>200 字符标量值 → 截断加 '…'
test('④ 值长度截断：>200 字符标量值被截断并加省略号', function () {
    $long = str_repeat('a', 250);
    $payload = buildSystemAlert([
        'admin_email' => 'ops@test.local',
        'details' => [
            'long' => $long,
            'short' => 'ok',
        ],
    ]);

    $details = $payload->data['details'];
    expect(mb_strlen($details['long']))->toBe(201) // 200 + '…'
        ->and($details['long'])->toEndWith('…')
        ->and($details['long'])->not->toBe($long)
        ->and($details['short'])->toBe('ok');
});

// ④-r3 键数上界：超过 20 键截断并追加占位
test('④-r3 键数上界：超过 20 键截断并追加 [truncated:N keys] 占位', function () {
    $details = [];
    for ($i = 1; $i <= 25; $i++) {
        $details["k$i"] = "v$i";
    }

    $payload = buildSystemAlert([
        'admin_email' => 'ops@test.local',
        'details' => $details,
    ]);

    $out = $payload->data['details'];
    expect(count($out))->toBe(21) // 前 20 键保留 + 1 占位
        ->and($out['k20'])->toBe('v20')
        ->and($out)->not->toHaveKey('k21')
        ->and($out)->not->toHaveKey('k25')
        ->and($out['_truncated'])->toBe('[truncated:5 keys]');
});

test('④-r3 键数上界：恰好 20 键不截断、无占位', function () {
    $details = [];
    for ($i = 1; $i <= 20; $i++) {
        $details["k$i"] = "v$i";
    }

    $payload = buildSystemAlert([
        'admin_email' => 'ops@test.local',
        'details' => $details,
    ]);

    expect(count($payload->data['details']))->toBe(20)
        ->and($payload->data['details'])->not->toHaveKey('_truncated');
});

// ⑤ 外部可控字符串截断 + 模板转义
test('⑤ title 截断 ≤100 / message 截断 ≤500', function () {
    $payload = buildSystemAlert([
        'admin_email' => 'ops@test.local',
        'title' => str_repeat('标', 150),
        'message' => str_repeat('m', 700),
    ]);

    expect(mb_strlen($payload->data['title']))->toBe(100)
        ->and(mb_strlen($payload->data['message']))->toBe(500);
});

test('⑤ message 含 <script> 经 system_alert 模板渲染后被 HTML 转义', function () {
    $seeder = new NotificationTemplateSeeder;
    $method = new ReflectionMethod($seeder, 'getSystemAlertHtml');
    $method->setAccessible(true);
    $html = $method->invoke($seeder);

    // 模板源码不得含 unescaped Blade 输出
    expect($html)->not->toContain('{!!');

    $rendered = Blade::render($html, [
        'category' => 'ca_credentials',
        'title' => '告警标题',
        'message' => '<script>alert(1)</script>',
        'details' => ['probe' => 'get-products'],
    ]);

    expect($rendered)->not->toContain('<script>alert(1)</script>')
        ->and($rendered)->toContain('&lt;script&gt;')
        ->and($rendered)->toContain('告警标题')
        ->and($rendered)->toContain('get-products');
});

test('⑤ details 键/值经模板渲染保持 HTML 转义（键含尖括号被转义）', function () {
    $seeder = new NotificationTemplateSeeder;
    $method = new ReflectionMethod($seeder, 'getSystemAlertHtml');
    $method->setAccessible(true);
    $html = $method->invoke($seeder);

    $rendered = Blade::render($html, [
        'category' => 'x',
        'title' => 't',
        'message' => 'm',
        'details' => ['<b>k</b>' => '<i>v</i>'],
    ]);

    expect($rendered)->not->toContain('<b>k</b>')
        ->and($rendered)->not->toContain('<i>v</i>')
        ->and($rendered)->toContain('&lt;b&gt;');
});

// ⑥ I2 关键用例（读端）：admin_email 显式错开 notifiable->email
test('⑥ I2 读端：context.admin_email 与 notifiable->email 错开时，email 取 admin_email', function () {
    $admin = Mockery::mock(Admin::class)->makePartial();
    // notifiable 的邮箱与 adminEmail 显式不同，防两值相同的假绿
    $admin->shouldReceive('getAttribute')->with('email')->andReturn('admin@corp.example');

    $payload = buildSystemAlert([
        'admin_email' => 'ops-alias@corp.example',
        'category' => 'x',
        'title' => 't',
        'message' => 'm',
    ], $admin);

    expect($payload->data['email'])->toBe('ops-alias@corp.example')
        ->and($payload->data['_meta']['email'])->toBe('ops-alias@corp.example')
        ->and($payload->data['admin_email'])->toBe('ops-alias@corp.example');
});

test('⑥ admin_email 缺失时回落 notifiable->email', function () {
    $admin = Mockery::mock(Admin::class)->makePartial();
    $admin->shouldReceive('getAttribute')->with('email')->andReturn('fallback@corp.example');

    $payload = buildSystemAlert([
        'category' => 'x',
        'title' => 't',
        'message' => 'm',
    ], $admin);

    expect($payload->data['email'])->toBe('fallback@corp.example');
});

test('Seeder 的 system_alert 模板能用 Blade 正确渲染（含空 details）', function () {
    $seeder = new NotificationTemplateSeeder;
    $method = new ReflectionMethod($seeder, 'getSystemAlertHtml');
    $method->setAccessible(true);
    $html = $method->invoke($seeder);

    $rendered = Blade::render($html, [
        'category' => 'clock',
        'title' => '服务器时钟偏差',
        'message' => '偏差约 130s',
        'details' => [],
    ]);

    expect($rendered)->toContain('服务器时钟偏差')
        ->and($rendered)->toContain('偏差约 130s');
});
