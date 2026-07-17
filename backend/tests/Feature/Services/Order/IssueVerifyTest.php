<?php

use App\Exceptions\ApiResponseException;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Order\Utils\VerifyUtil;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function setIssueVerifyDnsTools(array $urls): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'dnsTools'],
        ['type' => 'array', 'value' => $urls, 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);
}

function makeIssueVerifyOrder(string $alternativeNames): Order
{
    $product = Product::factory()->create([
        'ca' => 'digicert',
        'product_type' => Product::TYPE_SSL,
    ]);
    $order = Order::factory()->for($product)->create();
    $cert = Cert::factory()->for($order)->create([
        'status' => 'unpaid',
        'alternative_names' => $alternativeNames,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    return $order;
}

beforeEach(function () {
    setIssueVerifyDnsTools(['http://dnstool.test']);
    Http::preventStrayRequests();
});

test('混合 SAN 的签发预检只发送 DNS 域名', function () {
    $order = makeIssueVerifyOrder('example.com,202.155.152.20,2602:f864:218:10::a');
    Http::fake([
        'dnstool.test/*' => Http::response(['code' => 1, 'data' => null]),
    ]);

    VerifyUtil::issueVerify([$order->id]);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://dnstool.test/api/domain/issue-verify'
        && $request['brand'] === 'digicert'
        && $request['domains'] === 'example.com'
    );
});

test('纯 IP SAN 的签发预检不请求 CAA 服务', function () {
    $order = makeIssueVerifyOrder('202.155.152.20,2602:f864:218:10::a');
    Http::fake();

    VerifyUtil::issueVerify([$order->id]);

    Http::assertNothingSent();
});

test('DNS 域名签发预检仍透传远端失败', function () {
    $order = makeIssueVerifyOrder('blocked.example.com');
    Http::fake([
        'dnstool.test/*' => Http::response([
            'code' => 0,
            'msg' => '验证失败：1/1 域名未通过',
            'errors' => [[
                'display_domain' => 'blocked.example.com',
                'valid' => false,
                'message' => 'CAA 不允许当前 CA',
                'errors' => ['forbidden'],
            ]],
        ]),
    ]);

    try {
        VerifyUtil::issueVerify([$order->id]);
        test()->fail('远端 CAA 校验失败时应阻断签发预检');
    } catch (ApiResponseException $e) {
        expect($e->getApiResponse())->toMatchArray([
            'code' => 0,
            'msg' => '验证失败：1/1 域名未通过',
            'errors' => [[
                'blocked.example.com' => [
                    '说明' => 'CAA 不允许当前 CA',
                    '错误' => ['forbidden'],
                ],
            ]],
        ]);
    }
});

test('签发预检首节点连接失败时故障转移到下一节点', function () {
    setIssueVerifyDnsTools(['http://dnstool1.test', 'http://dnstool2.test']);
    $order = makeIssueVerifyOrder('example.com');
    $firstAttempts = 0;
    Http::fake([
        'dnstool1.test/*' => function () use (&$firstAttempts) {
            $firstAttempts++;

            throw new ConnectionException('connection failed');
        },
        'dnstool2.test/*' => Http::response(['code' => 1, 'data' => null]),
    ]);

    VerifyUtil::issueVerify([$order->id]);

    expect($firstAttempts)->toBe(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://dnstool2.test/api/domain/issue-verify'
    );
});

test('签发预检所有节点 HTTP 失败时保持 fail-open', function () {
    setIssueVerifyDnsTools(['http://dnstool1.test', 'http://dnstool2.test']);
    $order = makeIssueVerifyOrder('example.com');
    Http::fake([
        'dnstool1.test/*' => Http::response(['code' => 0], 500),
        'dnstool2.test/*' => Http::response(['code' => 0], 503),
    ]);

    VerifyUtil::issueVerify([$order->id]);

    Http::assertSentCount(2);
});
