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

test('legacy finance_audit_alert is renamed to finance_audit, admin config preserved, no orphan', function () {
    // 模拟 main 存量：旧 code = finance_audit_alert 行（管理员做过自定义内容），
    // 且当前 seeder 已不会再插它。迁移须先把它改名为 finance_audit 保留配置，无孤儿。
    DB::statement('ALTER TABLE notification_templates DROP INDEX notification_templates_code_index');
    DB::statement('ALTER TABLE notification_templates ADD INDEX notification_templates_code_index (code)');

    // 清掉 seeder 默认的 finance_audit，制造"仅有旧 code 行"的场景
    DB::table('notification_templates')->whereIn('code', ['finance_audit', 'finance_audit_alert'])->delete();

    $legacyId = DB::table('notification_templates')->insertGetId([
        'name' => '资金审计告警',
        'code' => 'finance_audit_alert',
        'content' => '管理员自定义资金审计内容 {{ $violation_count }}',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_05_26_094239_simplify_notification_system.php');
    $migration->up();

    // 旧 code 不再存在（无孤儿死数据）
    expect(DB::table('notification_templates')->where('code', 'finance_audit_alert')->exists())->toBeFalse();

    // 同一行被改名为 finance_audit，管理员自定义内容保留
    $renamed = DB::table('notification_templates')->where('code', 'finance_audit')->get();
    expect($renamed)->toHaveCount(1);
    expect($renamed->first()->id)->toBe($legacyId);
    expect($renamed->first()->content)->toContain('管理员自定义资金审计内容');
});

test('dedup keeps the enabled (status=1) row even when a disabled row has the smaller id', function () {
    // #23/#24: 去重不能无条件保留 MIN(id)。若某 code 最小 id 行是禁用(status=0)、
    // 启用(status=1)行 id 更大，应保留启用行（旧语义=保留最小 id 的启用行）。
    DB::statement('ALTER TABLE notification_templates DROP INDEX notification_templates_code_index');
    DB::statement('ALTER TABLE notification_templates ADD INDEX notification_templates_code_index (code)');

    DB::table('notification_templates')->where('code', 'cert_expire')->delete();

    $disabledLowId = DB::table('notification_templates')->insertGetId([
        'name' => '证书到期提醒(禁用)',
        'code' => 'cert_expire',
        'content' => '禁用旧行',
        'status' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $enabledHighId = DB::table('notification_templates')->insertGetId([
        'name' => '证书到期提醒(启用)',
        'code' => 'cert_expire',
        'content' => '启用生效行',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($disabledLowId)->toBeLessThan($enabledHighId);

    $migration = require database_path('migrations/2026_05_26_094239_simplify_notification_system.php');
    $migration->up();

    $rows = DB::table('notification_templates')->where('code', 'cert_expire')->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->id)->toBe($enabledHighId, '应保留启用行而非最小 id 的禁用行');
    expect($rows->first()->content)->toBe('启用生效行');
});

test('rename then dedup converges when both finance_audit_alert and finance_audit exist; rerun is idempotent', function () {
    // 改名后可能与既有 finance_audit 行重复，必须由去重步骤收敛；且重复跑 up() 幂等。
    DB::statement('ALTER TABLE notification_templates DROP INDEX notification_templates_code_index');
    DB::statement('ALTER TABLE notification_templates ADD INDEX notification_templates_code_index (code)');

    DB::table('notification_templates')->whereIn('code', ['finance_audit', 'finance_audit_alert'])->delete();

    // 旧 code 启用行（改名后将与下面的 finance_audit 同 code）
    $legacyEnabledId = DB::table('notification_templates')->insertGetId([
        'name' => '资金审计(旧启用)',
        'code' => 'finance_audit_alert',
        'content' => '旧启用行',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // 已存在的 finance_audit 禁用行（id 更大）
    $newDisabledId = DB::table('notification_templates')->insertGetId([
        'name' => '资金审计(新禁用)',
        'code' => 'finance_audit',
        'content' => '新禁用行',
        'status' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect($legacyEnabledId)->toBeLessThan($newDisabledId);

    $migration = require database_path('migrations/2026_05_26_094239_simplify_notification_system.php');
    $migration->up();

    $rows = DB::table('notification_templates')->where('code', 'finance_audit')->get();
    expect($rows)->toHaveCount(1);
    // 保留启用行（旧启用行改名而来），无 finance_audit_alert 残留
    expect($rows->first()->id)->toBe($legacyEnabledId);
    expect($rows->first()->content)->toBe('旧启用行');
    expect(DB::table('notification_templates')->where('code', 'finance_audit_alert')->exists())->toBeFalse();

    // 幂等：再跑一次不报错、不改变结果
    $migration->up();
    $again = DB::table('notification_templates')->where('code', 'finance_audit')->get();
    expect($again)->toHaveCount(1);
    expect($again->first()->id)->toBe($legacyEnabledId);
});
