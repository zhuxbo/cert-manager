<?php

use App\Models\CaLog;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Acme\Api\default\Sdk;
use App\Services\LogBuffer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses()->group('database');

beforeEach(function () {
    LogBuffer::clear();
    app()->forgetInstance('correlation_id');

    $group = SettingGroup::firstOrCreate(['name' => 'ca'], ['title' => '证书接口', 'weight' => 2]);
    foreach (['acme_url' => 'https://acme.test/api/acme', 'acme_token' => 'tk-test'] as $key => $value) {
        $setting = Setting::firstOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            ['type' => 'string', 'value' => null, 'weight' => 0]
        );
        $setting->value = $value;
        $setting->save();
    }
});

afterEach(function () {
    app()->forgetInstance('correlation_id');
});

test('ACME Sdk new() 调用上游成功后写入一条 ca_logs', function () {
    $cid = '11111111-aaaa-2222-bbbb-333333333333';
    app()->instance('correlation_id', $cid);

    Http::fake([
        'acme.test/api/acme/new' => Http::response(['code' => 1, 'data' => ['order_id' => 7]], 200),
    ]);

    $sdk = new Sdk;
    $result = $sdk->new(['contact_email' => 'foo@example.com', 'product_code' => 'p1']);

    expect($result['code'])->toBe(1);

    LogBuffer::flush();

    $log = CaLog::latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->api)->toBe('new');
    expect($log->url)->toBe('https://acme.test/api/acme');
    expect($log->status_code)->toBe(200);
    expect((int) $log->status)->toBe(1);
    expect($log->correlation_id)->toBe($cid);
    expect($log->params)->toMatchArray([
        'contact_email' => 'foo@example.com',
        'product_code' => 'p1',
    ]);
});

test('ACME Sdk 调用含敏感字段时被脱敏写入 ca_logs', function () {
    Http::fake([
        'acme.test/api/acme/new' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 7,
                'eab_hmac' => 'super-secret-hmac-value',
                'access_token' => 'bearer-leaked-token',
            ],
        ], 200),
    ]);

    $sdk = new Sdk;
    $sdk->new([
        'contact_email' => 'foo@example.com',
        'eab_hmac' => 'plain-hmac',
        'token' => 'plain-token',
    ]);

    LogBuffer::flush();

    $log = CaLog::latest('id')->first();
    expect($log)->not->toBeNull();

    // params 中的敏感字段被脱敏
    $params = $log->params;
    expect($params['eab_hmac'])->toBe('******');
    expect($params['token'])->toBe('******');
    expect($params['contact_email'])->toBe('foo@example.com');

    // response 中的敏感字段也被脱敏
    $response = $log->response;
    expect($response['data']['access_token'])->toBe('******');
    expect($response['data']['eab_hmac'])->toBe('******');
    expect($response['data']['order_id'])->toBe(7);
});

test('ACME Sdk 调用失败 (HTTP 500) 时 ca_logs status=0 + status_code=500', function () {
    Http::fake([
        'acme.test/api/acme/new' => Http::response(['msg' => 'upstream error'], 500),
    ]);

    $sdk = new Sdk;
    $result = $sdk->new(['contact_email' => 'foo@example.com']);

    expect($result['code'])->toBe(0);

    LogBuffer::flush();

    $log = CaLog::latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->status_code)->toBe(500);
    expect((int) $log->status)->toBe(0);
});

test('ACME Sdk 连接失败 (ConnectionException) 时 ca_logs status=0 + status_code=0', function () {
    Http::fake(function () {
        throw new ConnectionException('connection refused');
    });

    $sdk = new Sdk;
    $result = $sdk->new(['contact_email' => 'foo@example.com']);

    expect($result['code'])->toBe(0);
    expect($result['msg'])->toBe('上游连接失败');

    LogBuffer::flush();

    $log = CaLog::latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->status_code)->toBe(0);
    expect((int) $log->status)->toBe(0);
});
