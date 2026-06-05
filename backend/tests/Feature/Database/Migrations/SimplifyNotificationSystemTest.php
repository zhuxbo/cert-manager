<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 回归测试：
 * - SHOW INDEX ... WHERE Column_name = ? 在 Laravel 默认 EMULATE_PREPARES=false
 *   下会抛 SQLSTATE[42000] 1064，导致迁移看似执行成功但索引升级实际没生效。
 * - 迁移 up() 必须幂等：channels 列、code 索引被改过的环境重跑不应报错。
 */
test('migration upgrades code index to unique', function () {
    // RefreshDatabase 已跑过全部迁移；如果迁移内 SHOW INDEX 占位符 bug 复发，
    // 这里 code 列就只会有普通索引而非 unique。
    $rows = collect(DB::select('SHOW INDEX FROM notification_templates'))
        ->where('Column_name', 'code');

    $unique = $rows->firstWhere('Non_unique', 0);

    expect($unique)->not->toBeNull('code 列应升级为 unique 索引');
    expect($unique->Key_name)->toBe('notification_templates_code_index');
});

test('migration up is idempotent on already-upgraded schema', function () {
    $migration = require database_path('migrations/2026_05_26_094239_simplify_notification_system.php');

    // 第二次跑 up() — channels 已删、索引已 unique，应当 early return 不报错
    $migration->up();

    $unique = collect(DB::select('SHOW INDEX FROM notification_templates'))
        ->where('Column_name', 'code')
        ->firstWhere('Non_unique', 0);

    expect($unique)->not->toBeNull('幂等重跑后索引仍应是 unique');
    expect(Schema::hasColumn('notification_templates', 'channels'))->toBeFalse();
});

test('migration up recovers when migrations row exists but index still non-unique', function () {
    // 模拟线上"migrations 已记录但 ensureUniqueCodeOnNotificationTemplates 没生效"的状态：
    // 把唯一索引降回普通索引，再跑一次 up() 验证修复路径
    DB::statement('ALTER TABLE notification_templates DROP INDEX notification_templates_code_index');
    DB::statement('ALTER TABLE notification_templates ADD INDEX notification_templates_code_index (code)');

    $beforeRow = collect(DB::select('SHOW INDEX FROM notification_templates'))
        ->where('Column_name', 'code')
        ->firstWhere('Key_name', 'notification_templates_code_index');
    expect((int) $beforeRow->Non_unique)->toBe(1, '前置条件：先降回普通索引');

    $migration = require database_path('migrations/2026_05_26_094239_simplify_notification_system.php');
    $migration->up();

    $afterRow = collect(DB::select('SHOW INDEX FROM notification_templates'))
        ->where('Column_name', 'code')
        ->firstWhere('Key_name', 'notification_templates_code_index');
    expect((int) $afterRow->Non_unique)->toBe(0, '重跑后应升级为 unique');
});

test('legacy sms+mail dual rows: cleanup drops pure-sms row before dedup, mail HTML template survives', function () {
    // 模拟 main 存量数据：cert_issued 同时有短信版(低 id, 纯文本, channels=[sms]) 与
    // 邮件版(高 id, HTML, channels=[mail]) 两行。验证 up() 的 cleanup 先删纯 sms 行，
    // 邮件 HTML 模板存活——而非被 ensureUnique 的 MIN(id) 去重错误保留短信纯文本。
    Schema::table('notification_templates', function ($table) {
        $table->text('channels')->nullable();
    });
    DB::statement('ALTER TABLE notification_templates DROP INDEX notification_templates_code_index');
    DB::statement('ALTER TABLE notification_templates ADD INDEX notification_templates_code_index (code)');

    DB::table('notification_templates')->where('code', 'cert_issued')->delete();
    $smsId = DB::table('notification_templates')->insertGetId([
        'name' => '证书签发通知',
        'code' => 'cert_issued',
        'content' => '您好 {{ $username }}，您的证书 {{ $domain }} 已签发。',
        'channels' => json_encode(['sms']),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $mailId = DB::table('notification_templates')->insertGetId([
        'name' => '证书签发通知',
        'code' => 'cert_issued',
        'content' => '<!DOCTYPE html><html><body>证书已签发邮件 HTML 模板</body></html>',
        'channels' => json_encode(['mail']),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($smsId)->toBeLessThan($mailId);

    $migration = require database_path('migrations/2026_05_26_094239_simplify_notification_system.php');
    $migration->up();

    $rows = DB::table('notification_templates')->where('code', 'cert_issued')->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->id)->toBe($mailId);
    expect($rows->first()->content)->toContain('<html>');
});
