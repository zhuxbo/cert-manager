<?php

use App\Services\Logs\CoreLogRetentionPolicy;

test('核心日志审计动作按动作保留且不受成功失败影响', function (string $channel, ?string $module, ?string $action, ?string $method, ?string $url, ?string $api) {
    $policy = new CoreLogRetentionPolicy;

    foreach ([0, 1] as $status) {
        expect($policy->classify($channel, $module, $action, $method, $url, $api, $status))
            ->toBe(['audit' => true, 'classified' => true]);
    }
})->with([
    '用户登录' => ['user', 'Auth', 'login', 'POST', '/api/login', null],
    '用户下单' => ['user', 'Order', 'new', 'POST', '/api/order', null],
    '用户提交签发' => ['user', 'Order', 'commit', 'POST', '/api/order/commit', null],
    '用户取消' => ['user', 'Order', 'commitCancel', 'POST', '/api/order/cancel', null],
    'API 下单' => ['api', 'Api', 'new', 'POST', '/api/v2/new', null],
    'CA 下单' => ['ca', null, null, null, null, 'new'],
]);

test('诊断动作不因失败升级为审计日志', function (string $channel, ?string $module, ?string $action, ?string $method, ?string $url, ?string $api) {
    $policy = new CoreLogRetentionPolicy;

    expect($policy->classify($channel, $module, $action, $method, $url, $api, 0))
        ->toBe(['audit' => false, 'classified' => true]);
})->with([
    '用户 revalidate 失败' => ['user', 'Order', 'revalidate', 'POST', '/api/order/revalidate', null],
    '用户 sync 失败' => ['user', 'Order', 'sync', 'POST', '/api/order/sync', null],
    '管理员 GET' => ['admin', 'Order', 'get', 'GET', '/api/admin/order/1', null],
    '管理员 POST revalidate' => ['admin', 'Order', 'revalidate', 'POST', '/api/admin/order/revalidate', null],
    'API revalidate' => ['api', 'Api', 'revalidate', 'POST', '/api/v2/revalidate', null],
    'CA revalidate' => ['ca', null, null, null, null, 'revalidate'],
    '异常 revalidate' => ['error', 'Order', 'revalidate', 'POST', '/api/order/revalidate', null],
]);

test('用户端只读路由统一按诊断日志保留', function (string $module, string $action, string $url) {
    $policy = new CoreLogRetentionPolicy;

    expect($policy->classify('user', $module, $action, 'GET', $url, null, 1))
        ->toBe(['audit' => false, 'classified' => true]);
})->with([
    '首页概览' => ['Dashboard', 'overview', '/api/dashboard/overview'],
    '证书下载信息' => ['Order', 'certs', '/api/order/1/certs'],
    '订单部署命令' => ['Order', 'deployCommands', '/api/order/deploy-commands'],
    '设置读取' => ['Setting', 'getNotificationPreferences', '/api/setting/notification-preferences'],
    '未来新增只读动作' => ['FutureModule', 'futureReadAction', '/api/future/read'],
]);

test('未来未知动作保守保留并标记为未分类', function (string $channel) {
    $policy = new CoreLogRetentionPolicy;

    expect($policy->classify($channel, 'FutureSecurity', 'rotateCredential', 'POST', '/future', null, 0))
        ->toBe(['audit' => true, 'classified' => false]);
})->with(['user', 'admin', 'api', 'ca', 'error']);

test('回调只有已识别业务成功记录进入长期审计', function () {
    $policy = new CoreLogRetentionPolicy;

    expect($policy->classify('callback', 'TopUp', 'alipayNotify', 'POST', '/callback/alipay', null, 1))
        ->toBe(['audit' => true, 'classified' => true])
        ->and($policy->classify('callback', 'TopUp', 'alipayNotify', 'POST', '/callback/alipay', null, 0))
        ->toBe(['audit' => false, 'classified' => true])
        ->and($policy->classify('callback', null, null, 'POST', '/callback/unknown', null, 0))
        ->toBe(['audit' => false, 'classified' => true]);
});

test('历史空动作通过已知 URL 或 API 字段分类', function () {
    $policy = new CoreLogRetentionPolicy;

    expect($policy->classify('api', null, null, 'POST', 'https://manager.test/api/v2/revalidate', null, 0))
        ->toBe(['audit' => false, 'classified' => true])
        ->and($policy->classify('callback', null, null, 'POST', 'https://manager.test/callback/alipay', null, 1))
        ->toBe(['audit' => true, 'classified' => true])
        ->and($policy->classify('ca', null, null, null, null, 'upload-document', 0))
        ->toBe(['audit' => true, 'classified' => true]);
});
