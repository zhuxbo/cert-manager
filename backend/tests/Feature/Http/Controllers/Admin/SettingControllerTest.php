<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

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

    $response = $this->actingAsAdmin($this->admin)->putJson('/api/admin/setting/batch-update', [
        'settings' => $updateData,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    foreach ($settings as $setting) {
        $setting->refresh();
        expect($setting->value)->toBe('batch_updated');
    }
});

test('管理员可以删除设置', function () {
    $setting = Setting::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/setting/$setting->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Setting::find($setting->id))->toBeNull();
});

test('管理员可以清除设置缓存', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/setting/clear-cache');

    $response->assertOk()->assertJson(['code' => 1]);
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
