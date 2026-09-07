<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\ErrorLog;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Delegation\DelegationConfigService;
use App\Services\LogBuffer;
use Database\Seeders\SettingSeeder;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

function settingControllerCreateDelegationDomain(string $domain): Setting
{
    $group = SettingGroup::firstOrCreate(
        ['name' => 'delegation'],
        ['title' => '委托设置', 'weight' => 1],
    );
    $config = app(DelegationConfigService::class);

    return Setting::create([
        'group_id' => $group->id,
        'key' => $config->keyForDomain($domain),
        'type' => 'array',
        'value' => [
            'domain' => $domain,
            'provider' => 'cloudflare',
            'zoneId' => 'test-zone',
            'apiToken' => 'test-token',
        ],
        'weight' => 1,
    ]);
}

function settingControllerCreateDefaultDomain(string $domain): Setting
{
    $group = SettingGroup::firstOrCreate(
        ['name' => 'delegation'],
        ['title' => '委托设置', 'weight' => 1],
    );

    return Setting::create([
        'group_id' => $group->id,
        'key' => 'delegationDomain',
        'type' => 'string',
        'value' => $domain,
        'weight' => 2,
    ]);
}

function settingControllerCreateDelegation(string $domain, string $label): CnameDelegation
{
    return CnameDelegation::factory()->create([
        'proxy_domain' => $domain,
        'label' => $label,
    ]);
}

test('管理员可以获取所有设置', function () {
    $group = SettingGroup::factory()->create();
    Setting::factory()->count(3)->create(['group_id' => $group->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/setting');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['groups']]);
});

test('管理员可以获取指定组的设置', function () {
    $group = SettingGroup::factory()->create();
    Setting::factory()->count(3)->create(['group_id' => $group->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/setting/group/$group->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['group']]);
});

test('管理员手工添加的可选设置会正常显示', function () {
    $group = SettingGroup::factory()->create(['name' => 'site']);
    Setting::factory()->create([
        'group_id' => $group->id,
        'key' => 'autoRefundOnSync',
        'type' => 'boolean',
        'value' => true,
    ]);

    $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/setting')
        ->assertOk()
        ->assertJsonFragment([
            'key' => 'autoRefundOnSync',
            'value' => true,
        ]);

    $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/setting/group/$group->id")
        ->assertOk()
        ->assertJsonFragment([
            'key' => 'autoRefundOnSync',
            'value' => true,
        ]);
});

test('获取不存在的设置组返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/setting/group/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以查看设置详情', function () {
    $setting = Setting::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/setting/$setting->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $setting->id);
});

test('管理员可以添加设置项', function () {
    $group = SettingGroup::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/setting', [
        'group_id' => $group->id,
        'key' => 'test_key',
        'type' => 'string',
        'value' => 'test_value',
        'description' => '测试设置',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Setting::where('key', 'test_key')->exists())->toBeTrue();
});

test('管理员可以创建空值的图片设置项', function () {
    $group = SettingGroup::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/setting', [
        'group_id' => $group->id,
        'key' => 'image',
        'type' => 'image',
        'value' => '',
        'description' => '图片设置',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Setting::where('group_id', $group->id)->where('key', 'image')->value('type'))->toBe('image');
});

test('管理员可以更新设置', function () {
    $setting = Setting::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/setting/$setting->id", [
        'group_id' => $setting->group_id,
        'key' => $setting->key,
        'type' => $setting->type,
        'value' => 'updated_value',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $setting->refresh();
    expect($setting->value)->toBe('updated_value');
});

test('管理员可以批量更新设置', function () {
    $settings = Setting::factory()->count(3)->create();

    $updateData = $settings->map(function ($setting) {
        return ['id' => $setting->id, 'value' => 'batch_updated'];
    })->toArray();

    $response = $this->actingAsAdmin($this->admin)->patchJson('/api/admin/setting/batch-update', [
        'settings' => $updateData,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    foreach ($settings as $setting) {
        $setting->refresh();
        expect($setting->value)->toBe('batch_updated');
    }
});

test('管理员批量更新不能原地改写委托域身份', function () {
    expectsBreakingChange('delegation-settings-2026-09: 委托设置拒绝操作改为 HTTP 200、code=0 业务提示，不再返回 HTTP 400');
    $setting = settingControllerCreateDelegationDomain('proxy.example.com');
    $delegation = settingControllerCreateDelegation('proxy.example.com', str_repeat('f', 32));
    Cert::factory()->create([
        'status' => 'active',
        'validation' => [['delegation_id' => $delegation->id]],
    ]);

    $response = $this->actingAsAdmin($this->admin)->patchJson('/api/admin/setting/batch-update', [
        'settings' => [[
            'id' => $setting->id,
            'value' => [
                'domain' => 'proxy-example.com',
                'provider' => 'cloudflare',
                'zoneId' => 'new-zone',
                'apiToken' => 'new-token',
            ],
        ]],
    ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect($setting->fresh()->value['domain'])->toBe('proxy.example.com')
        ->and($delegation->fresh())->not->toBeNull();
});

test('管理员单条更新不能原地改写委托域身份但可以切换 provider', function () {
    expectsBreakingChange('delegation-settings-2026-09: 委托设置拒绝操作改为 HTTP 200、code=0 业务提示，不再返回 HTTP 400');
    $setting = settingControllerCreateDelegationDomain('proxy.example.com');

    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/setting/$setting->id", [
            'group_id' => $setting->group_id,
            'key' => $setting->key,
            'type' => $setting->type,
            'value' => [
                'domain' => 'proxy-example.com',
                'provider' => 'cloudflare',
                'zoneId' => 'renamed-zone',
                'apiToken' => 'renamed-token',
            ],
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect($setting->fresh()->value['domain'])->toBe('proxy.example.com');

    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/setting/$setting->id", [
            'group_id' => $setting->group_id,
            'key' => $setting->key,
            'type' => $setting->type,
            'value' => [
                'domain' => 'proxy.example.com',
                'provider' => 'tencent',
                'secretId' => 'new-secret-id',
                'secretKey' => 'new-secret-key',
            ],
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($setting->fresh()->value)->toMatchArray([
        'domain' => 'proxy.example.com',
        'provider' => 'tencent',
        'secretId' => 'new-secret-id',
        'secretKey' => 'new-secret-key',
    ]);
});

test('管理员可编辑 provider 示例并改成域名派生 key 后启用', function () {
    $group = SettingGroup::create([
        'name' => 'delegation',
        'title' => '域名委托',
        'weight' => 3,
    ]);
    $setting = Setting::create([
        'group_id' => $group->id,
        'key' => 'aliyun',
        'type' => 'array',
        'value' => [
            'domain' => '',
            'provider' => 'aliyun',
            'accessKeyId' => '',
            'accessKeySecret' => '',
        ],
    ]);

    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/setting/$setting->id", [
            'group_id' => $group->id,
            'key' => 'proxyExampleCom',
            'type' => 'array',
            'value' => [
                'domain' => 'proxy.example.com',
                'provider' => 'aliyun',
                'accessKeyId' => 'access-key-id',
                'accessKeySecret' => 'access-key-secret',
            ],
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $setting->refresh();
    expect($setting->key)->toBe('proxyExampleCom')
        ->and(app(DelegationConfigService::class)->get('proxy.example.com'))
        ->toMatchArray([
            'provider' => 'aliyun',
            'accessKeyId' => 'access-key-id',
            'accessKeySecret' => 'access-key-secret',
        ]);
});

test('管理员保留示例键名即可启用委托域并继续维护凭据', function () {
    $this->seed(SettingSeeder::class);
    $group = SettingGroup::where('name', 'delegation')->firstOrFail();
    $setting = Setting::where('group_id', $group->id)->where('key', 'cloudflare')->firstOrFail();
    $config = [
        'domain' => 'proxy.example.com',
        'provider' => 'cloudflare',
        'zoneId' => 'zone-id',
        'apiToken' => 'initial-token',
    ];
    $service = app(DelegationConfigService::class);
    expect($service->get('proxy.example.com'))->toBe([]);

    $this->actingAsAdmin($this->admin)
        ->patchJson('/api/admin/setting/batch-update', [
            'settings' => [
                ['id' => $setting->id, 'value' => $config],
                ['id' => Setting::where('group_id', $group->id)->where('key', 'delegationDomain')->firstOrFail()->id, 'value' => 'proxy.example.com'],
            ],
        ])
        ->assertOk()->assertJson(['code' => 1]);

    expect($setting->fresh()->key)->toBe('cloudflare')
        ->and($service->get($service->defaultDomain()))->toMatchArray($config);

    $config['apiToken'] = 'updated-token';
    $this->actingAsAdmin($this->admin)
        ->putJson("/api/admin/setting/$setting->id", [
            'group_id' => $group->id,
            'key' => 'cloudflare',
            'type' => 'array',
            'value' => $config,
        ])
        ->assertOk()->assertJson(['code' => 1]);
    expect($service->get('proxy.example.com')['apiToken'])->toBe('updated-token');

    $config['domain'] = 'other.example.com';
    $this->actingAsAdmin($this->admin)
        ->patchJson('/api/admin/setting/batch-update', [
            'settings' => [['id' => $setting->id, 'value' => $config]],
        ])
        ->assertOk()->assertJson(['code' => 0]);
    $this->actingAsAdmin($this->admin)
        ->deleteJson("/api/admin/setting/$setting->id")
        ->assertOk()->assertJson(['code' => 0, 'msg' => '当前默认委托域不能删除']);
});

test('管理员可以删除设置', function () {
    $setting = Setting::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/setting/$setting->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Setting::find($setting->id))->toBeNull();
});

test('管理员不能删除 defaultDomain 设置本身', function () {
    expectsBreakingChange('delegation-settings-2026-09: 委托设置拒绝操作改为 HTTP 200、code=0 业务提示，不再返回 HTTP 400');
    $default = settingControllerCreateDefaultDomain('proxy.example.com');

    $response = $this->actingAsAdmin($this->admin)
        ->deleteJson("/api/admin/setting/$default->id");

    $response->assertOk()
        ->assertJson(['code' => 0, 'msg' => '默认委托域设置不能删除']);
    expect($default->fresh())->not->toBeNull();
});

test('管理员删除委托域时只按 proxy_domain 计数保护', function () {
    expectsBreakingChange('delegation-settings-2026-09: 委托设置拒绝操作改为 HTTP 200、code=0 业务提示，不再返回 HTTP 400');
    $setting = settingControllerCreateDelegationDomain('proxy.example.com');
    $delegations = collect([
        settingControllerCreateDelegation('proxy.example.com', str_repeat('a', 32)),
        settingControllerCreateDelegation('proxy.example.com', str_repeat('b', 32)),
    ]);

    $response = $this->actingAsAdmin($this->admin)
        ->deleteJson("/api/admin/setting/$setting->id");

    $response->assertOk()
        ->assertJson(['code' => 0, 'msg' => '仍有 2 条委托记录使用该委托域']);
    expect($setting->fresh())->not->toBeNull()
        ->and($delegations->every(fn (CnameDelegation $delegation) => $delegation->fresh() !== null))->toBeTrue();
});

test('批删先预检所有委托域且任一计数检查失败时一个都不删除', function () {
    expectsBreakingChange('delegation-settings-2026-09: 委托设置拒绝操作改为 HTTP 200、code=0 业务提示，不再返回 HTTP 400');
    $eligible = settingControllerCreateDelegationDomain('eligible.example.com');
    $blocked = settingControllerCreateDelegationDomain('blocked.example.com');
    $blockedDelegation = settingControllerCreateDelegation('blocked.example.com', str_repeat('c', 32));
    $ordinary = Setting::factory()->create();

    $response = $this->actingAsAdmin($this->admin)
        ->deleteJson('/api/admin/setting/batch', [
            'ids' => [$eligible->id, $blocked->id, $ordinary->id],
        ]);

    $response->assertOk()
        ->assertJson(['code' => 0, 'msg' => '仍有 1 条委托记录使用该委托域']);
    expect($eligible->fresh())->not->toBeNull()
        ->and($blocked->fresh())->not->toBeNull()
        ->and($blockedDelegation->fresh())->not->toBeNull()
        ->and($ordinary->fresh())->not->toBeNull();
});

test('批删通过全量预检后删除委托设置并保留普通设置删除行为', function () {
    $first = settingControllerCreateDelegationDomain('first.example.com');
    $second = settingControllerCreateDelegationDomain('second.example.com');
    $ordinary = Setting::factory()->create();
    $unrelated = settingControllerCreateDelegation('other.example.com', str_repeat('e', 32));

    $response = $this->actingAsAdmin($this->admin)
        ->deleteJson('/api/admin/setting/batch', [
            'ids' => [$first->id, $second->id, $ordinary->id],
        ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($first->fresh())->toBeNull()
        ->and($second->fresh())->toBeNull()
        ->and($ordinary->fresh())->toBeNull()
        ->and($unrelated->fresh())->not->toBeNull();
});

test('delegation 组畸形域配置批删失败关闭且普通组设置保留待单独处理', function () {
    expectsBreakingChange('delegation-settings-2026-09: 委托设置拒绝操作改为 HTTP 200、code=0 业务提示，不再返回 HTTP 400');
    $delegationGroup = SettingGroup::firstOrCreate(
        ['name' => 'delegation'],
        ['title' => '委托设置', 'weight' => 1],
    );
    $otherGroup = SettingGroup::factory()->create(['name' => 'other-settings']);
    $wrongType = Setting::create([
        'group_id' => $delegationGroup->id,
        'key' => 'wrongTypeExampleCom',
        'type' => 'string',
        'value' => 'wrong-type.example.com',
    ]);
    $wrongKey = Setting::create([
        'group_id' => $delegationGroup->id,
        'key' => 'doesNotMatch',
        'type' => 'array',
        'value' => ['domain' => 'wrong-key.example.com'],
    ]);
    $wrongGroup = Setting::create([
        'group_id' => $otherGroup->id,
        'key' => 'wrongGroupExampleCom',
        'type' => 'array',
        'value' => ['domain' => 'wrong-group.example.com'],
    ]);
    $response = $this->actingAsAdmin($this->admin)
        ->deleteJson('/api/admin/setting/batch', [
            'ids' => [$wrongType->id, $wrongKey->id, $wrongGroup->id],
        ]);

    $response->assertOk()->assertJson(['code' => 0]);
    expect($wrongType->fresh())->not->toBeNull()
        ->and($wrongKey->fresh())->not->toBeNull()
        ->and($wrongGroup->fresh())->not->toBeNull();
});

test('管理员可以清除设置缓存', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/setting/clear-cache');

    $response->assertOk()->assertJson(['code' => 1]);
});

test('管理后台安全刷新设置缓存并保留框架运行状态', function () {
    $viewFile = storage_path('framework/views/pest-safe-clear.view.php');
    $sessionFile = storage_path('framework/sessions/pest-safe-clear.session');
    File::ensureDirectoryExists(dirname($viewFile));
    File::ensureDirectoryExists(dirname($sessionFile));
    File::put($viewFile, 'view');
    File::put($sessionFile, 'session');

    $group = SettingGroup::factory()->create(['name' => 'safe-clear']);
    Cache::put("setting:group:{$group->id}", ['stale' => true], 600);
    Cache::put('admin-safe-clear-unregistered', 'preserved', 600);
    Cache::store('runtime')->put('admin-safe-clear-runtime', 'critical', 600);
    Cache::forever('illuminate:queue:restart', 1234567890);

    $queue = app('queue');
    $queue->pause('database', 'safe-clear');

    $mutex = app(CacheEventMutex::class);
    $event = (new Event($mutex, 'php artisan inspire'))
        ->name('pest-safe-clear-scheduler')
        ->withoutOverlapping();
    expect($mutex->create($event))->toBeTrue();

    try {
        $this->actingAsAdmin($this->admin)
            ->postJson('/api/admin/setting/clear-all-cache')
            ->assertOk()
            ->assertJson(['code' => 1]);

        expect(Cache::get("setting:group:{$group->id}"))->toBeNull()
            ->and(Cache::get('admin-safe-clear-unregistered'))->toBe('preserved')
            ->and(Cache::store('runtime')->get('admin-safe-clear-runtime'))->toBe('critical')
            ->and(Cache::get('illuminate:queue:restart'))->toBe(1234567890)
            ->and($queue->isPaused('database', 'safe-clear'))->toBeTrue()
            ->and($mutex->exists($event))->toBeTrue()
            ->and(File::exists($viewFile))->toBeTrue()
            ->and(File::exists($sessionFile))->toBeTrue();
    } finally {
        $queue->resume('database', 'safe-clear');
        Cache::forget('illuminate:queue:restart');
        Cache::forget('admin-safe-clear-unregistered');
        Cache::store('runtime')->forget('admin-safe-clear-runtime');
        $mutex->forget($event);
        File::delete([$viewFile, $sessionFile]);
    }
});

test('管理后台安全刷新在默认缓存指向 runtime 时也不删除其它键', function () {
    config(['cache.default' => 'runtime']);
    Cache::store('runtime')->put('safe-clear-overlap-critical', 'preserved', 600);

    try {
        $this->actingAsAdmin($this->admin)
            ->postJson('/api/admin/setting/clear-all-cache')
            ->assertOk()
            ->assertJson(['code' => 1]);

        expect(Cache::store('runtime')->get('safe-clear-overlap-critical'))->toBe('preserved');
    } finally {
        Cache::store('runtime')->forget('safe-clear-overlap-critical');
    }
});

test('未认证用户无法访问设置管理', function () {
    $response = $this->getJson('/api/admin/setting');

    $response->assertUnauthorized();
});

test('管理员上传站点 Logo 后更新设置并清理旧托管文件', function () {
    Storage::fake('public');
    $oldPath = 'site/logo-'.str_repeat('a', 64).'.png';
    Storage::disk('public')->put($oldPath, 'old');
    $group = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => '站点设置', 'weight' => 1],
    );
    $setting = Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'logo'],
        ['type' => 'image', 'value' => '/api/meta/site-image/'.basename($oldPath)],
    );

    $response = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => UploadedFile::fake()->image('brand.png', 200, 200)->size(100),
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $url = $response->json('data.url');
    expect($url)->toMatch('#^/api/meta/site-image/logo-[a-f0-9]{64}\.png$#')
        ->and($setting->fresh()->value)->toBe($url);
    Storage::disk('public')->assertExists('site/'.basename($url));
    Storage::disk('public')->assertMissing($oldPath);
    $imageResponse = $this->get($url)->assertOk();
    expect($imageResponse->headers->get('Cache-Control'))
        ->toContain('public')
        ->toContain('max-age=31536000')
        ->toContain('immutable');
});

test('管理员只能上传内容合法的 ICO Favicon', function () {
    Storage::fake('public');
    $group = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => '站点设置', 'weight' => 1],
    );
    $setting = Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'favicon'],
        ['type' => 'image', 'value' => ''],
    );
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );
    expect($png)->toBeString();
    $ico = pack('vvv', 0, 1, 1)
        .chr(1).chr(1).chr(0).chr(0)
        .pack('vvVV', 1, 32, strlen($png), 22)
        .$png;

    $response = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/favicon', [
        'file' => UploadedFile::fake()->createWithContent('favicon.ico', $ico),
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $url = $response->json('data.url');
    expect($url)->toMatch('#^/api/meta/site-image/favicon-[a-f0-9]{64}\.ico$#')
        ->and($setting->fresh()->value)->toBe($url);
    Storage::disk('public')->assertExists('site/'.basename($url));
    $this->get($url)
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $dib = pack('V3v2V6', 40, 1, 2, 1, 32, 0, 4, 0, 0, 0, 0)
        .pack('C4', 0, 0, 0, 255)
        .pack('V', 0);
    $dibIco = pack('vvv', 0, 1, 1)
        .chr(1).chr(1).chr(0).chr(0)
        .pack('vvVV', 1, 32, strlen($dib), 22)
        .$dib;
    $this->actingAsAdmin($this->admin)
        ->post('/api/admin/setting/site-image/favicon', [
            'file' => UploadedFile::fake()->createWithContent('favicon.ico', $dibIco),
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    foreach ([
        UploadedFile::fake()->createWithContent('fake.ico', 'not-an-icon'),
        UploadedFile::fake()->createWithContent(
            'broken-payload.ico',
            pack('vvv', 0, 1, 1)
                .chr(1).chr(1).chr(0).chr(0)
                .pack('vvVV', 1, 32, 4, 22)
                .'ICON',
        ),
        UploadedFile::fake()->createWithContent(
            'broken-png.ico',
            pack('vvv', 0, 1, 1)
                .chr(1).chr(1).chr(0).chr(0)
                .pack('vvVV', 1, 32, 14, 22)
                ."\x89PNG\r\n\x1a\nBROKEN",
        ),
        UploadedFile::fake()->createWithContent('favicon.png', $ico),
    ] as $invalidFile) {
        $this->actingAsAdmin($this->admin)
            ->post('/api/admin/setting/site-image/favicon', ['file' => $invalidFile])
            ->assertOk()
            ->assertJson(['code' => 0])
            ->assertJsonPath('errors.file.0', 'Favicon 仅支持 ICO 格式');
    }
});

test('管理员可以上传 SVG Logo 但二维码不接受 SVG', function () {
    Storage::fake('public');
    $group = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => '站点设置', 'weight' => 1],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'logo'],
        ['type' => 'image', 'value' => ''],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'logoExpanded'],
        ['type' => 'image', 'value' => ''],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'qrcode'],
        ['type' => 'image', 'value' => ''],
    );
    $svg = UploadedFile::fake()->createWithContent(
        'brand.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg>',
    );

    $logo = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => $svg,
    ]);

    $logo->assertOk()->assertJson(['code' => 1]);
    $url = $logo->json('data.url');
    expect($url)->toMatch('#^/api/meta/site-image/logo-[a-f0-9]{64}\.svg$#');
    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $expandedLogo = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo-expanded', [
        'file' => UploadedFile::fake()->createWithContent(
            'brand-expanded.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10"><path d="M0 0h20v10H0z"/></svg>',
        ),
    ]);
    $expandedLogo->assertOk()->assertJson(['code' => 1]);
    expect($expandedLogo->json('data.url'))
        ->toMatch('#^/api/meta/site-image/logo-expanded-[a-f0-9]{64}\.svg$#')
        ->and(Setting::where('group_id', $group->id)->where('key', 'logoExpanded')->value('value'))
        ->toBe($expandedLogo->json('data.url'));

    $qrcode = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/qrcode', [
        'file' => UploadedFile::fake()->createWithContent('wechat.svg', $svg->getContent()),
    ]);
    $qrcode->assertOk()->assertJson(['code' => 0]);

    // 矢量 SVG 不受 200×200 像素上限约束（大 viewBox 的真实矢量 Logo 应可上传）
    $largeViewBox = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => UploadedFile::fake()->createWithContent(
            'vector.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path d="M0 0h1024v1024H0z"/></svg>',
        ),
    ]);
    $largeViewBox->assertOk()->assertJson(['code' => 1]);

    // 带前置 Generator 注释的真实导出 SVG（Inkscape/Illustrator）应可上传
    $withComment = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => UploadedFile::fake()->createWithContent(
            'inkscape.svg',
            "<?xml version=\"1.0\"?>\n<!-- Created with Inkscape (http://www.inkscape.org/) -->\n<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 48 48\"><path d=\"M0 0h48v48H0z\"/></svg>",
        ),
    ]);
    $withComment->assertOk()->assertJson(['code' => 1]);

    $rectangularLogo = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => UploadedFile::fake()->createWithContent(
            'rectangular.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 24"><path d="M0 0h48v24H0z"/></svg>',
        ),
    ]);
    $rectangularLogo->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('errors.file.0', '普通 Logo 必须为正方形');

    $conflictingDimensions = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => UploadedFile::fake()->createWithContent(
            'conflicting-dimensions.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 100 100"><path d="M0 0h100v100H0z"/></svg>',
        ),
    ]);
    $conflictingDimensions->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('errors.file.0', '普通 Logo 必须为正方形');

    $matchingDimensions = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => UploadedFile::fake()->createWithContent(
            'matching-dimensions.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="100px" height="100px" viewBox="0 0 100 100"></svg>',
        ),
    ]);
    $matchingDimensions->assertOk()->assertJson(['code' => 1]);

    foreach ([
        '<svg xmlns="http://www.w3.org/2000/svg" width="100px" height="100cm" viewBox="0 0 100 100"></svg>',
        '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="50%" viewBox="0 0 100 100"></svg>',
    ] as $svgWithConflictingUnits) {
        $this->actingAsAdmin($this->admin)
            ->post('/api/admin/setting/site-image/logo', [
                'file' => UploadedFile::fake()->createWithContent('conflicting-units.svg', $svgWithConflictingUnits),
            ])
            ->assertOk()
            ->assertJson(['code' => 0])
            ->assertJsonPath('errors.file.0', '普通 Logo 必须为正方形');
    }

    // 带 DOCTYPE 的 SVG（实体注入面）仍被安全校验拒绝
    $unsafe = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => UploadedFile::fake()->createWithContent(
            'unsafe.svg',
            '<!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg>',
        ),
    ]);
    $unsafe->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('errors.file.0', 'SVG 文件格式不合法');
});

test('站点图片上传限制尺寸和文件大小', function () {
    Storage::fake('public');
    $group = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => '站点设置', 'weight' => 1],
    );
    foreach (['logo', 'logoExpanded', 'qrcode'] as $key) {
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            ['type' => 'image', 'value' => ''],
        );
    }

    $validLogo = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => UploadedFile::fake()->image('logo.png', 200, 200)->size(200),
    ]);
    $validLogo->assertOk()->assertJson(['code' => 1]);

    $validExpandedLogo = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo-expanded', [
        'file' => UploadedFile::fake()->image('logo-expanded.png', 200, 80)->size(200),
    ]);
    $validExpandedLogo->assertOk()->assertJson(['code' => 1]);

    $rectangularLogo = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => UploadedFile::fake()->image('rectangular-logo.png', 200, 100)->size(200),
    ]);
    $rectangularLogo->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('errors.file.0', '普通 Logo 必须为正方形');

    foreach ([
        UploadedFile::fake()->image('wide-logo.png', 201, 200)->size(200),
        UploadedFile::fake()->image('large-logo.png', 200, 200)->size(201),
    ] as $file) {
        $this->actingAsAdmin($this->admin)
            ->post('/api/admin/setting/site-image/logo', ['file' => $file])
            ->assertOk()
            ->assertJson(['code' => 0]);
    }

    $validQrcode = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/qrcode', [
        'file' => UploadedFile::fake()->image('qrcode.png', 800, 800)->size(1024),
    ]);
    $validQrcode->assertOk()->assertJson(['code' => 1]);

    $rectangularQrcode = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/qrcode', [
        'file' => UploadedFile::fake()->image('rectangular-qrcode.png', 800, 400)->size(1024),
    ]);
    $rectangularQrcode->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('errors.file.0', '二维码必须为正方形');

    foreach ([
        UploadedFile::fake()->image('wide-qrcode.png', 801, 800)->size(1024),
        UploadedFile::fake()->image('large-qrcode.png', 800, 800)->size(1025),
    ] as $file) {
        $this->actingAsAdmin($this->admin)
            ->post('/api/admin/setting/site-image/qrcode', ['file' => $file])
            ->assertOk()
            ->assertJson(['code' => 0]);
    }
});

test('管理员可以上传二维码且非图片文件会被拒绝', function () {
    Storage::fake('public');
    $group = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => '站点设置', 'weight' => 1],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'qrcode'],
        ['type' => 'image', 'value' => '/qrcode.png'],
    );

    $invalid = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/qrcode', [
        'file' => UploadedFile::fake()->create('payload.txt', 10, 'text/plain'),
    ]);
    $invalid->assertOk()->assertJson(['code' => 0]);

    $valid = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/qrcode', [
        'file' => UploadedFile::fake()->image('wechat.jpg', 512, 512)->size(200),
    ]);
    $valid->assertOk()->assertJson(['code' => 1]);
    expect($valid->json('data.url'))->toMatch('#^/api/meta/site-image/qrcode-[a-f0-9]{64}\.jpg$#');
});

test('管理员可以上传自由比例的登录配图且限制尺寸', function () {
    Storage::fake('public');
    $group = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => '站点设置', 'weight' => 1],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'loginImage'],
        ['type' => 'image', 'value' => ''],
    );

    // 自由比例（竖版）位图可上传，不要求正方形
    $valid = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/login-image', [
        'file' => UploadedFile::fake()->image('login.png', 1200, 1800)->size(2048),
    ]);
    $valid->assertOk()->assertJson(['code' => 1]);
    $url = $valid->json('data.url');
    expect($url)->toMatch('#^/api/meta/site-image/login-image-[a-f0-9]{64}\.png$#')
        ->and(Setting::where('group_id', $group->id)->where('key', 'loginImage')->value('value'))
        ->toBe($url);

    // 消费端路由必须放行 login-image 文件名（漏配会导致上传成功但页面 404 不显示）
    $this->get($url)->assertOk();

    // 登录配图不接受 SVG
    $svg = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/login-image', [
        'file' => UploadedFile::fake()->createWithContent(
            'login.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg>',
        ),
    ]);
    $svg->assertOk()->assertJson(['code' => 0]);

    // 超出 2560×2560 或 2MB 拒绝
    foreach ([
        UploadedFile::fake()->image('wide-login.png', 2561, 1440)->size(1024),
        UploadedFile::fake()->image('large-login.png', 1200, 1800)->size(2049),
    ] as $file) {
        $this->actingAsAdmin($this->admin)
            ->post('/api/admin/setting/site-image/login-image', ['file' => $file])
            ->assertOk()
            ->assertJson(['code' => 0]);
    }
});

test('管理员可以清除登录配图并删除托管文件', function () {
    Storage::fake('public');
    $group = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => '站点设置', 'weight' => 1],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'loginImage'],
        ['type' => 'image', 'value' => ''],
    );

    $upload = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/login-image', [
        'file' => UploadedFile::fake()->image('login.png', 1200, 800)->size(512),
    ]);
    $upload->assertOk()->assertJson(['code' => 1]);
    $path = 'site/'.basename($upload->json('data.url'));
    expect(Storage::disk('public')->exists($path))->toBeTrue();

    $this->actingAsAdmin($this->admin)
        ->delete('/api/admin/setting/site-image/login-image')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Setting::where('group_id', $group->id)->where('key', 'loginImage')->value('value'))->toBe('')
        ->and(Storage::disk('public')->exists($path))->toBeFalse();

    // 未上传状态重复清除幂等
    $this->actingAsAdmin($this->admin)
        ->delete('/api/admin/setting/site-image/login-image')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('站点图片上传只接受 logo 和 qrcode 类型', function () {
    Storage::fake('public');

    $response = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/banner', [
        'file' => UploadedFile::fake()->image('banner.png'),
    ]);

    $response->assertNotFound();
});

test('站点图片上传要求设置项为图片类型', function () {
    Storage::fake('public');
    $group = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => '站点设置', 'weight' => 1],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'logo'],
        ['type' => 'string', 'value' => ''],
    );

    $response = $this->actingAsAdmin($this->admin)->post('/api/admin/setting/site-image/logo', [
        'file' => UploadedFile::fake()->image('logo.png', 200, 200),
    ]);

    $response->assertOk()->assertJson(['code' => 0, 'msg' => '站点图片设置不存在']);
});

test('管理员可以修改已启用委托配置键名且域名身份不变', function () {
    $setting = settingControllerCreateDelegationDomain('proxy.example.com');
    $value = $setting->value;

    $this->actingAsAdmin($this->admin)->putJson("/api/admin/setting/$setting->id", [
        'group_id' => $setting->group_id,
        'key' => 'cloudflare',
        'type' => 'array',
        'value' => $value,
    ])->assertOk()->assertJson(['code' => 1]);

    expect($setting->fresh()->key)->toBe('cloudflare')
        ->and($setting->fresh()->value)->toBe($value)
        ->and(app(DelegationConfigService::class)->get('proxy.example.com'))->toBe($value);
});

test('委托配置改名不能占用默认域保留键或顺带改变域名', function (string $key, string $domain) {
    $setting = settingControllerCreateDelegationDomain('proxy.example.com');
    $value = $setting->value;
    $this->actingAsAdmin($this->admin)->putJson("/api/admin/setting/$setting->id", [
        'group_id' => $setting->group_id,
        'key' => $key,
        'type' => 'array',
        'value' => array_replace($value, ['domain' => $domain]),
    ])->assertOk()->assertJson(['code' => 0]);
    expect($setting->fresh()->key)->toBe($setting->key)
        ->and($setting->fresh()->value)->toBe($value);
})->with([
    ['delegationDomain', 'proxy.example.com'],
    ['cloudflare', 'other.example.com'],
]);

test('修改默认委托保留键返回业务提示且不记录异常', function (bool $debug) {
    config(['app.debug' => $debug]);
    $setting = settingControllerCreateDefaultDomain('proxy.example.com');
    LogBuffer::clear();
    $errorCount = ErrorLog::count();

    $this->actingAsAdmin($this->admin)->putJson("/api/admin/setting/$setting->id", [
        'group_id' => $setting->group_id,
        'key' => 'renamedDomain',
        'type' => 'string',
        'value' => $setting->value,
    ])->assertOk()->assertExactJson([
        'code' => 0,
        'msg' => '默认委托域设置键名不能修改',
    ]);

    LogBuffer::flush();
    expect(ErrorLog::count())->toBe($errorCount)
        ->and($setting->fresh()->key)->toBe('delegationDomain')
        ->and($setting->fresh()->value)->toBe('proxy.example.com');
})->with([true, false]);
