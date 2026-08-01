<?php

use App\Models\NotificationTemplate;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed(NotificationTemplateSeeder::class);
});

test('到期汇总模板展示混合产品类型与各自证书标识', function () {
    $rendered = NotificationTemplate::where('code', 'cert_expire')->firstOrFail()->render([
        'username' => 'testuser',
        'site_url' => 'https://ssl.test/',
        'site_name' => '证书管理系统',
        'has_ssl_certificate' => true,
        'certificates' => [
            [
                'product_type_label' => 'SSL',
                'domain' => 'www.example.com',
                'expire_at' => '2026-08-01',
                'days_left' => 2,
            ],
            [
                'product_type_label' => 'S/MIME',
                'domain' => 'mail@example.com',
                'expire_at' => '2026-08-02',
                'days_left' => 3,
            ],
            [
                'product_type_label' => '代码签名',
                'domain' => 'Example Software',
                'expire_at' => '2026-08-03',
                'days_left' => 4,
            ],
            [
                'product_type_label' => '文档签名',
                'domain' => 'Example Document',
                'expire_at' => '2026-08-04',
                'days_left' => 5,
            ],
        ],
    ]);

    expect($rendered)
        ->toContain('证书到期提醒')
        ->toContain('SSL')
        ->toContain('www.example.com')
        ->toContain('S/MIME')
        ->toContain('mail@example.com')
        ->toContain('代码签名')
        ->toContain('Example Software')
        ->toContain('文档签名')
        ->toContain('Example Document');
});

test('非 SSL 模板使用通用信任与签名文案', function (string $code, array $data, string $expectedLabel) {
    $rendered = NotificationTemplate::where('code', $code)->firstOrFail()->render($data);

    expect($rendered)
        ->toContain($expectedLabel)
        ->toContain('身份验证')
        ->not->toContain('浏览器')
        ->not->toContain('HTTPS')
        ->not->toContain('域名验证');
})->with([
    '到期 S/MIME' => [
        'cert_expire',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'has_ssl_certificate' => false,
            'certificates' => [[
                'product_type_label' => 'S/MIME',
                'domain' => 'mail@example.com',
                'expire_at' => '2026-08-01',
                'days_left' => 2,
            ]],
        ],
        'S/MIME',
    ],
    '停滞代码签名' => [
        'cert_renew_stalled',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'has_ssl_certificate' => false,
            'certificates' => [[
                'product_type_label' => '代码签名',
                'domain' => 'Example Software',
                'expire_at' => '2026-08-01',
                'days_left' => 2,
                'stall_status' => 'processing',
                'action_hint' => '请完成身份验证或签名材料审核。',
            ]],
        ],
        '代码签名',
    ],
    '取消文档签名' => [
        'cert_renew_cancelled',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'common_name' => 'Example Document',
            'expires_at' => '2026-08-01',
            'order_id' => 1001,
            'action' => '续期',
            'product_type' => 'docsign',
            'product_type_label' => '文档签名',
        ],
        '文档签名',
    ],
    '吊销代码签名' => [
        'cert_revoked',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'common_name' => 'Example Software',
            'expires_at' => '2026-08-01',
            'order_id' => 1002,
            'is_successor' => false,
            'product_type' => 'codesign',
            'product_type_label' => '代码签名',
        ],
        '代码签名',
    ],
]);

test('单证书通知标题带类型时详情不重复展示证书类型', function (string $code, array $data, string $expectedTitle) {
    $rendered = NotificationTemplate::where('code', $code)->firstOrFail()->render($data);

    expect($rendered)
        ->toContain($expectedTitle)
        ->not->toContain('证书类型');
})->with([
    '签发' => [
        'cert_issued',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'product' => 'S/MIME 产品',
            'domain' => 'mail@example.com',
            'product_type_label' => 'S/MIME',
            'has_attachment' => true,
        ],
        'S/MIME 证书已成功签发',
    ],
    '续签取消' => [
        'cert_renew_cancelled',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'common_name' => 'mail@example.com',
            'expires_at' => '2026-08-01',
            'order_id' => 1001,
            'action' => '续期',
            'product_type' => 'smime',
            'product_type_label' => 'S/MIME',
        ],
        'S/MIME 证书续签取消提醒',
    ],
    '吊销' => [
        'cert_revoked',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'common_name' => 'mail@example.com',
            'expires_at' => '2026-08-01',
            'order_id' => 1002,
            'is_successor' => true,
            'product_type' => 'smime',
            'product_type_label' => 'S/MIME',
        ],
        'S/MIME 证书吊销提醒',
    ],
]);

test('续签取消与新证书吊销文案不再使用接替术语', function () {
    $cancelled = NotificationTemplate::where('code', 'cert_renew_cancelled')->firstOrFail()->render([
        'username' => 'testuser',
        'site_url' => 'https://ssl.test/',
        'site_name' => '证书管理系统',
        'common_name' => 'mail@example.com',
        'expires_at' => '2026-08-01',
        'order_id' => 1001,
        'product_type' => 'smime',
        'product_type_label' => 'S/MIME',
    ]);
    $revoked = NotificationTemplate::where('code', 'cert_revoked')->firstOrFail()->render([
        'username' => 'testuser',
        'site_url' => 'https://ssl.test/',
        'site_name' => '证书管理系统',
        'common_name' => 'mail@example.com',
        'expires_at' => '2026-08-01',
        'order_id' => 1002,
        'is_successor' => true,
        'product_type' => 'smime',
        'product_type_label' => 'S/MIME',
    ]);

    expect($cancelled)
        ->toContain('证书续签取消提醒')
        ->toContain('证书续签订单')
        ->not->toContain('接替')
        ->and($revoked)
        ->toContain('续签后签发的新证书')
        ->not->toContain('接替');
});

test('旧队列载荷缺少产品类型字段时仍按 SSL 安全渲染', function (string $code, array $data) {
    $rendered = NotificationTemplate::where('code', $code)->firstOrFail()->render($data);

    expect($rendered)->toContain('SSL');
})->with([
    '签发旧载荷' => [
        'cert_issued',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'product' => 'SSL 产品',
            'domain' => 'www.example.com',
            'has_attachment' => true,
        ],
    ],
    '到期旧载荷' => [
        'cert_expire',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'certificates' => [[
                'domain' => 'www.example.com',
                'expire_at' => '2026-08-01',
                'days_left' => 2,
            ]],
        ],
    ],
    '停滞旧载荷' => [
        'cert_renew_stalled',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'certificates' => [[
                'domain' => 'www.example.com',
                'expire_at' => '2026-08-01',
                'days_left' => 2,
                'stall_status' => 'processing',
                'action_hint' => '请完成验证。',
            ]],
        ],
    ],
    '取消旧载荷' => [
        'cert_renew_cancelled',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'common_name' => 'www.example.com',
            'expires_at' => '2026-08-01',
            'order_id' => 1001,
            'action' => '续期',
        ],
    ],
    '吊销旧载荷' => [
        'cert_revoked',
        [
            'username' => 'testuser',
            'site_url' => 'https://ssl.test/',
            'site_name' => '证书管理系统',
            'common_name' => 'www.example.com',
            'expires_at' => '2026-08-01',
            'order_id' => 1002,
            'is_successor' => false,
        ],
    ],
]);

test('重跑 seeder 不覆盖五类证书通知的自定义模板', function () {
    $codes = [
        'cert_issued',
        'cert_expire',
        'cert_renew_stalled',
        'cert_renew_cancelled',
        'cert_revoked',
    ];

    foreach ($codes as $code) {
        NotificationTemplate::where('code', $code)->firstOrFail()->update([
            'content' => "custom:{$code}",
        ]);
    }

    (new NotificationTemplateSeeder)->run();

    foreach ($codes as $code) {
        expect(NotificationTemplate::where('code', $code)->firstOrFail()->content)
            ->toBe("custom:{$code}");
    }
});
