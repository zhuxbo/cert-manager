<?php

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Plugins\CloudDeploy\Notifications\CloudDeployFailedNotificationBuilder;
use Plugins\CloudDeploy\Seeders\NotificationTemplateSeeder;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('Builder 白名单：误塞的 error/AK 不进 payload，仅 4 字段 + is_html=false', function () {
    $user = User::factory()->create();
    $intent = new NotificationIntent('cloud_deploy_failed', 'user', $user->id, [
        'product' => 'cdn',
        'domain' => 'a.example.com',
        'access_name' => '我的阿里云',
        'error_code' => 'retries_exhausted',
        // 恶意/误塞：绝不该进 notifications.data
        'error' => 'AccessKeyId=AKIDEXAMPLE Signature=wJalrXUt',
        'last_error' => 'AKIDEXAMPLE',
    ]);

    $payload = (new CloudDeployFailedNotificationBuilder)->build($intent, $user);

    $json = json_encode($payload->data, JSON_UNESCAPED_UNICODE);
    expect($payload->data['product'])->toBe('cdn')
        ->and($payload->data['domain'])->toBe('a.example.com')
        ->and($payload->data['access_name'])->toBe('我的阿里云')
        ->and($payload->data['error_code'])->toBe('retries_exhausted')
        ->and($payload->data['_meta']['is_html'])->toBeFalse()
        ->and($json)->not->toContain('AKIDEXAMPLE')   // AK 被白名单挡掉
        ->and($json)->not->toContain('Signature=');
    expect(array_keys($payload->data))->not->toContain('error');
    expect(array_keys($payload->data))->not->toContain('last_error');
});

test('Seeder 种入 cloud_deploy_failed 模板：启用且声明 Builder 提供的四个变量', function () {
    app(NotificationTemplateSeeder::class)->run();

    $row = DB::table('notification_templates')->where('code', 'cloud_deploy_failed')->first();
    expect($row)->not->toBeNull();
    expect((int) $row->status)->toBe(1);
    expect(base64_decode($row->content))->toContain('{{ $product }}')->toContain('{{ $domain }}');
    $variables = [
        'product',
        'domain',
        'access_name',
        'error_code',
    ];
    expect(json_decode((string) $row->variables, true))->toBe($variables);
    $template = NotificationTemplate::query()->where('code', 'cloud_deploy_failed')->firstOrFail();
    expect($template->variables)->toBe($variables);
    expect($template->render([
        'product' => 'cdn',
        'domain' => 'a.example.com',
        'access_name' => '测试云账号',
        'error_code' => 'retries_exhausted',
    ]))->toContain('产品：cdn')
        ->toContain('域名：a.example.com')
        ->toContain('云账号：测试云账号')
        ->toContain('错误码：retries_exhausted');
});
