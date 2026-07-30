<?php

use App\Models\AutoDeployReport;
use App\Models\Cert;
use App\Models\Contact;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Api\Api;
use Illuminate\Support\Carbon;
use Tests\Traits\ActsAsUser;
use Tests\Traits\CreatesTestData;
use Tests\Traits\MocksExternalApis;

uses(ActsAsUser::class, MocksExternalApis::class, CreatesTestData::class);

test('获取订单列表', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'active',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $otherOrder = Order::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
    ]);
    $otherCert = Cert::factory()->create([
        'order_id' => $otherOrder->id,
        'status' => 'active',
    ]);
    $otherOrder->update(['latest_cert_id' => $otherCert->id]);

    $response = $this->actingAsUser($user)
        ->getJson('/api/order')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);

    $itemIds = collect($response->json('data.items'))
        ->pluck('id')
        ->map(fn ($id) => (string) $id)
        ->all();

    expect($response->json('data.total'))
        ->toBe(1)
        ->and($response->json('data.items'))
        ->toHaveCount(1)
        ->and($itemIds)
        ->toContain((string) $order->id)
        ->not->toContain((string) $otherOrder->id);
});

test('获取订单列表-按状态筛选', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $archivedOrder = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $archivedCert = Cert::factory()->create([
        'order_id' => $archivedOrder->id,
        'status' => 'cancelled',
    ]);
    $archivedOrder->update(['latest_cert_id' => $archivedCert->id]);

    $response = $this->actingAsUser($user)
        ->getJson('/api/order?statusSet=activating')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.total'))
        ->toBe(1)
        ->and((string) $response->json('data.items.0.id'))
        ->toBe((string) $order->id)
        ->and($response->json('data.items.0.latest_cert.status'))
        ->toBe('active');
});

test('获取订单列表-按证书到期区间过滤默认活动中状态集并升序排列', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $certs = [
        [3, 'cancelled'],
        [6, 'active'],
        [2, 'active'],
        [10, 'failed'],
    ];
    $orders = collect($certs)->map(function (array $certData) use ($user, $product) {
        [$days, $status] = $certData;
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
        $cert = Cert::factory()->create([
            'order_id' => $order->id,
            'status' => $status,
            'expires_at' => now()->addDays($days),
        ]);
        $order->update(['latest_cert_id' => $cert->id]);

        return $order;
    });
    $query = http_build_query([
        'expires_at' => [
            now()->startOfDay()->format('Y-m-d\TH:i:s.v\Z'),
            now()->addDays(6)->endOfDay()->format('Y-m-d\TH:i:s.v\Z'),
        ],
        'sort_prop' => 'expires_at',
        'sort_order' => 'asc',
    ]);

    $response = $this->actingAsUser($user)
        ->getJson("/api/order?$query")
        ->assertOk()
        ->assertJson(['code' => 1]);

    // cancelled（3 天后到期）在区间内但不属于默认活动中状态集，不得出现
    expect(collect($response->json('data.items'))->pluck('id')->all())
        ->toBe([$orders[2]->id, $orders[1]->id]);
});

test('获取订单详情', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);
    AutoDeployReport::create([
        'order_id' => $order->id,
        'cert_id' => $cert->id,
        'status' => 'success',
        'deployed_at' => '2026-01-15 08:30:00',
        'ip' => '203.0.113.8',
    ]);

    $response = $this->actingAsUser($user)
        ->getJson("/api/order/$order->id")
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['id', 'product_id', 'product', 'latest_cert']]);

    expect((string) $response->json('data.id'))
        ->toBe((string) $order->id)
        ->and((string) $response->json('data.product_id'))
        ->toBe((string) $product->id)
        ->and((string) $response->json('data.latest_cert.id'))
        ->toBe((string) $cert->id)
        ->and($response->json('data'))
        ->not->toHaveKey('auto_deploy_reports');
});

test('获取订单详情-不能查看其他用户订单', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->actingAsUser($user)
        ->getJson("/api/order/$order->id")
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('获取订单详情-订单不存在', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/order/99999')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('新建订单', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create();

    $this->mockSdk();

    $response = $this->actingAsUser($user)
        ->postJson('/api/order/new', [
            'product_id' => $product->id,
            'period' => 12,
            'domains' => 'example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $newOrder = Order::withoutGlobalScopes()
        ->with('latestCert')
        ->find($response->json('data.order_id'));

    expect($newOrder)
        ->not->toBeNull()
        ->and($newOrder->user_id)
        ->toBe($user->id)
        ->and($newOrder->product_id)
        ->toBe($product->id)
        ->and($newOrder->latestCert)
        ->not->toBeNull()
        ->and($newOrder->latestCert->action)
        ->toBe('new')
        ->and($newOrder->latestCert->status)
        ->toBe('unpaid');
});

test('续费订单', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays(15),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->mockSdk();

    $response = $this->actingAsUser($user)
        ->postJson('/api/order/renew', [
            'order_id' => $order->id,
            'period' => 12,
            'domains' => 'example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $newOrder = Order::withoutGlobalScopes()
        ->with('latestCert')
        ->find($response->json('data.order_id'));
    $cert->refresh();

    expect($cert->status)
        ->toBe('renewed')
        ->and($newOrder)
        ->not->toBeNull()
        ->and($newOrder->user_id)
        ->toBe($user->id)
        ->and($newOrder->latestCert)
        ->not->toBeNull()
        ->and($newOrder->latestCert->action)
        ->toBe('renew')
        ->and($newOrder->latestCert->status)
        ->toBe('unpaid');
});

test('不可添加且不可替换 SAN 产品续费按合并后的最终数量拒绝新增 SAN', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create([
        'add_san' => 0,
        'replace_san' => 0,
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays(15),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'alternative_names' => 'old.example.com',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->actingAsUser($user)
        ->postJson('/api/order/renew', [
            'order_id' => $order->id,
            'period' => 12,
            'domains' => 'new.example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
        ])
        ->assertOk()
        ->assertJson([
            'code' => 0,
            'msg' => '标准域名数量超过原证书',
        ]);

    expect($cert->fresh()->status)
        ->toBe('active')
        ->and(Order::withoutGlobalScopes()->where('user_id', $user->id)->count())
        ->toBe(1);
});

test('不可添加且不可替换 SAN 产品续费允许继承原 SAN', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create([
        'add_san' => 0,
        'replace_san' => 0,
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays(15),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'alternative_names' => 'old.example.com',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->mockSdk();

    $response = $this->actingAsUser($user)
        ->postJson('/api/order/renew', [
            'order_id' => $order->id,
            'period' => 12,
            'domains' => 'old.example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $newOrder = Order::withoutGlobalScopes()
        ->with('latestCert')
        ->find($response->json('data.order_id'));

    expect($cert->fresh()->status)
        ->toBe('renewed')
        ->and($newOrder->latestCert->alternative_names)
        ->toBe('old.example.com')
        ->and($newOrder->latestCert->standard_count)
        ->toBe(1)
        ->and($newOrder->latestCert->wildcard_count)
        ->toBe(0);
});

test('重签订单', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->mockSdk();

    $response = $this->actingAsUser($user)
        ->postJson('/api/order/reissue', [
            'order_id' => $order->id,
            'domains' => 'example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $order->refresh();
    $cert->refresh();
    $newCert = Cert::withoutGlobalScopes()->find($order->latest_cert_id);

    expect((string) $response->json('data.order_id'))
        ->toBe((string) $order->id)
        ->and($cert->status)
        ->toBe('reissued')
        ->and($newCert)
        ->not->toBeNull()
        ->and($newCert->id)
        ->not->toBe($cert->id)
        ->and($newCert->action)
        ->toBe('reissue')
        ->and($newCert->status)
        ->toBe('unpaid');
});

test('不可添加 SAN 产品重签按订单已购数量拒绝超额 SAN', function (
    array $orderCounts,
    array $certCounts,
    string $domains,
    string $expectedMessage
) {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create([
        'add_san' => 0,
        'replace_san' => 1,
    ]);
    $order = Order::factory()->create(array_merge([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ], $orderCounts));
    $cert = Cert::factory()->active()->create(array_merge([
        'order_id' => $order->id,
        'alternative_names' => $domains,
    ], $certCounts));
    $order->update(['latest_cert_id' => $cert->id]);

    $this->actingAsUser($user)
        ->postJson('/api/order/reissue', [
            'order_id' => $order->id,
            'domains' => $domains,
            'validation_method' => 'txt',
            'csr_generate' => 1,
        ])
        ->assertOk()
        ->assertJson([
            'code' => 0,
            'msg' => $expectedMessage,
        ]);

    expect($cert->fresh()->status)
        ->toBe('active')
        ->and(Cert::where('order_id', $order->id)->count())
        ->toBe(1);
})->with([
    '标准 SAN' => [
        ['purchased_standard_count' => 1, 'purchased_wildcard_count' => 0],
        ['standard_count' => 2, 'wildcard_count' => 0],
        'one.example.com,two.example.com',
        '标准域名数量超过订单已购数量',
    ],
    '通配符 SAN' => [
        ['purchased_standard_count' => 1, 'purchased_wildcard_count' => 1],
        ['standard_count' => 1, 'wildcard_count' => 2],
        'example.com,*.one.example.com,*.two.example.com',
        '通配符域名数量超过订单已购数量',
    ],
]);

test('不可添加 SAN 产品重签允许恢复到订单已购 SAN 数量', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create([
        'add_san' => 0,
        'replace_san' => 1,
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'purchased_standard_count' => 2,
        'purchased_wildcard_count' => 0,
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'alternative_names' => 'one.example.com',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $response = $this->actingAsUser($user)
        ->postJson('/api/order/reissue', [
            'order_id' => $order->id,
            'domains' => 'one.example.com,two.example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $newCert = Cert::withoutGlobalScopes()->find($order->fresh()->latest_cert_id);

    expect((string) $response->json('data.order_id'))
        ->toBe((string) $order->id)
        ->and($cert->fresh()->status)
        ->toBe('reissued')
        ->and($newCert->standard_count)
        ->toBe(2)
        ->and($newCert->wildcard_count)
        ->toBe(0);
});

test('不可替换且不可添加 SAN 产品按合并后的最终数量校验重签 SAN', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create([
        'add_san' => 0,
        'replace_san' => 0,
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'purchased_standard_count' => 2,
        'purchased_wildcard_count' => 0,
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'alternative_names' => 'old-one.example.com,old-two.example.com',
        'standard_count' => 2,
        'wildcard_count' => 0,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->actingAsUser($user)
        ->postJson('/api/order/reissue', [
            'order_id' => $order->id,
            'domains' => 'new-one.example.com,new-two.example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
        ])
        ->assertOk()
        ->assertJson([
            'code' => 0,
            'msg' => '标准域名数量超过订单已购数量',
        ]);

    expect($cert->fresh()->status)
        ->toBe('active')
        ->and(Cert::where('order_id', $order->id)->count())
        ->toBe(1);
});

test('不可替换 SAN 产品合并后按完整域名集合扣除赠送根域名', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create([
        'add_san' => 0,
        'replace_san' => 0,
        'gift_root_domain' => 1,
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'alternative_names' => 'example.com',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $response = $this->actingAsUser($user)
        ->postJson('/api/order/reissue', [
            'order_id' => $order->id,
            'domains' => 'www.example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $newCert = Cert::withoutGlobalScopes()->find($order->fresh()->latest_cert_id);

    expect((string) $response->json('data.order_id'))
        ->toBe((string) $order->id)
        ->and($newCert->alternative_names)
        ->toContain('example.com')
        ->toContain('www.example.com')
        ->and($newCert->standard_count)
        ->toBe(1)
        ->and($newCert->wildcard_count)
        ->toBe(0);
});

test('批量获取订单详情', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $orders = [];
    for ($i = 0; $i < 3; $i++) {
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
        $cert = Cert::factory()->active()->create([
            'order_id' => $order->id,
        ]);
        $order->update(['latest_cert_id' => $cert->id]);
        AutoDeployReport::create([
            'order_id' => $order->id,
            'cert_id' => $cert->id,
            'status' => 'success',
            'ip' => "203.0.113.{$i}",
        ]);
        $orders[] = $order;
    }

    $otherUser = User::factory()->create();
    $otherOrder = Order::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
    ]);
    $otherCert = Cert::factory()->active()->create([
        'order_id' => $otherOrder->id,
    ]);
    $otherOrder->update(['latest_cert_id' => $otherCert->id]);

    $ids = collect($orders)->pluck('id')->push($otherOrder->id)->toArray();

    $response = $this->actingAsUser($user)
        ->getJson('/api/order/batch?ids='.implode(',', $ids))
        ->assertOk()
        ->assertJson(['code' => 1]);

    $returnedIds = collect($response->json('data.items'))
        ->pluck('id')
        ->map(fn ($id) => (string) $id)
        ->all();

    expect($response->json('data.items'))
        ->toHaveCount(3)
        ->and($returnedIds)
        ->not->toContain((string) $otherOrder->id)
        ->and((string) $response->json('data.balance'))
        ->toBe((string) $user->balance);

    foreach ($orders as $item) {
        expect($returnedIds)->toContain((string) $item->id);
    }

    expect($response->json('data.items.0'))->not->toHaveKey('auto_deploy_reports');
});

test('批量获取订单详情-全为其他用户订单返回不存在', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $product = Product::factory()->create();
    $otherOrder = Order::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
    ]);
    $otherCert = Cert::factory()->active()->create([
        'order_id' => $otherOrder->id,
    ]);
    $otherOrder->update(['latest_cert_id' => $otherCert->id]);

    $this->actingAsUser($user)
        ->getJson('/api/order/batch?ids='.$otherOrder->id)
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('订单列表-未认证', function () {
    $this->getJson('/api/order')
        ->assertUnauthorized();
});

test('新建订单-OV 未传 contact 时自动从企业反查联系人', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create(['validation_type' => 'ov']);
    $contact = Contact::factory()->create(['user_id' => $user->id]);
    $org = Organization::factory()->create([
        'user_id' => $user->id,
        'contact_id' => $contact->id,
    ]);

    $this->mockSdk();

    $resp = $this->actingAsUser($user)
        ->postJson('/api/order/new', [
            'product_id' => $product->id,
            'period' => 12,
            'domains' => 'example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
            'organization' => $org->id,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $order = Order::withoutGlobalScopes()->find($resp->json('data.order_id'));
    expect($order->contact)->not->toBeNull()
        ->and($order->contact['email'] ?? null)->toBe($contact->email);
});

test('新建订单-OV 企业未绑定联系人时报错', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create(['validation_type' => 'ov']);
    $org = Organization::factory()->create([
        'user_id' => $user->id,
        'contact_id' => null,
    ]);

    $this->mockSdk();

    $resp = $this->actingAsUser($user)
        ->postJson('/api/order/new', [
            'product_id' => $product->id,
            'period' => 12,
            'domains' => 'example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
            'organization' => $org->id,
        ])
        ->assertOk();

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('msg'))->toContain('联系人');
});

// ==================== 资金动作端到端（pay / commit / commit-cancel）====================
//
// 这些端点（pay/{id}、commit/{id}、commit-cancel/{id}）此前 User 端无任何用例。
// 这里真实执行（不 mock Action），仅在 commit 步骤 mock 最底层上游 Api（Action::__construct
// 走 app(Api::class)，容器替身生效），重点验证：
//   1. User 端这些端点真实可达（路由 + 控制器接线 + Action 真实跑通）
//   2. 真实扣费 / 退费 / 状态机
//   3. UserScope 越权边界：对他人订单执行资金动作必须落空（订单不存在）

/**
 * 造一个 user 名下的待扣费订单：unpaid 证书（action=new）+ 指定金额。
 * 建 ProductPrice 让 charge 组装交易备注走真实价格路径。
 */
function createUserUnpaidOrder(User $user, Product $product, string $amount): array
{
    ProductPrice::firstOrCreate(
        ['product_id' => $product->id, 'level_code' => 'standard', 'period' => 12],
        ['price' => $amount, 'alternative_standard_price' => '10.00', 'alternative_wildcard_price' => '20.00'],
    );

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
        'amount' => $amount,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'unpaid',
        'action' => 'new',
        'amount' => $amount,
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);

    $order->update(['latest_cert_id' => $cert->id]);
    $order->refresh();

    return [$order, $cert];
}

test('支付订单-pay→commit 端到端：真实扣费 + 真实提交上游', function () {
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = Product::factory()->create();
    [$order, $cert] = createUserUnpaidOrder($user, $product, '100.00');

    // mock 最底层上游 Api（commit 内 $this->api->new($data)）
    $mockApi = Mockery::mock(Api::class);
    $mockApi->shouldReceive('new')
        ->once()
        ->andReturn([
            'code' => 1,
            'data' => [
                'api_id' => 'CA-USER-123',
                'cert_apply_status' => 0,
                'dcv' => [['domain' => 'example.com', 'method' => 'txt']],
                'validation' => [],
            ],
        ]);
    $this->app->instance(Api::class, $mockApi);

    // issue_verify=false 跳过 DNS 网络校验；commit 默认 true → 扣费成功后立即提交
    $this->actingAsUser($user)
        ->postJson("/api/order/pay/$order->id", ['issue_verify' => false])
        ->assertOk()
        ->assertJson(['code' => 1]);

    // 真实扣费：余额 100 → 0，产生一条 type=order 扣费流水（-100）
    $user->refresh();
    expect((float) $user->balance)->toBe(0.0);
    $tx = Transaction::where('transaction_id', $order->id)->where('type', 'order')->get();
    expect($tx)->toHaveCount(1)
        ->and((float) $tx->first()->amount)->toBe(-100.0);

    // 真实提交：cert 写回上游 api_id，状态 unpaid → pending → processing
    $cert->refresh();
    expect($cert->status)->toBe('processing')
        ->and($cert->api_id)->toBe('CA-USER-123');
});

test('支付订单-不能支付其他用户的订单（UserScope 越权拒绝）', function () {
    $user = $this->createTestUser(['balance' => '100.00']);
    $otherUser = $this->createTestUser(['balance' => '100.00']);
    $product = Product::factory()->create();
    [$otherOrder, $otherCert] = createUserUnpaidOrder($otherUser, $product, '100.00');

    // 当前 user 尝试支付他人订单：UserScope 限制 charge 内 Order 查询落空
    $this->actingAsUser($user)
        ->postJson("/api/order/pay/$otherOrder->id", ['issue_verify' => false])
        ->assertOk()
        ->assertJson(['code' => 0]);

    // 越权未生效：两位用户余额都没动，订单仍 unpaid，无任何扣费流水
    $user->refresh();
    $otherUser->refresh();
    expect((float) $user->balance)->toBe(100.0)
        ->and((float) $otherUser->balance)->toBe(100.0)
        ->and($otherCert->fresh()->status)->toBe('unpaid')
        ->and(Transaction::where('transaction_id', $otherOrder->id)->count())->toBe(0);
});

test('提交订单-commit 端到端：pending 订单真实提交上游转 processing', function () {
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
    ]);
    // pending = 已扣费待提交
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'pending',
        'action' => 'new',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $mockApi = Mockery::mock(Api::class);
    $mockApi->shouldReceive('new')
        ->once()
        ->andReturn([
            'code' => 1,
            'data' => [
                'api_id' => 'CA-USER-COMMIT',
                'cert_apply_status' => 0,
                'dcv' => [['domain' => 'example.com', 'method' => 'txt']],
                'validation' => [],
            ],
        ]);
    $this->app->instance(Api::class, $mockApi);

    $this->actingAsUser($user)
        ->postJson("/api/order/commit/$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    $cert->refresh();
    expect($cert->status)->toBe('processing')
        ->and($cert->api_id)->toBe('CA-USER-COMMIT');
});

test('提交订单-不能提交其他用户的订单（UserScope 越权拒绝）', function () {
    $user = $this->createTestUser();
    $otherUser = $this->createTestUser();
    $product = Product::factory()->create();
    $otherOrder = Order::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
    ]);
    $otherCert = Cert::factory()->create([
        'order_id' => $otherOrder->id,
        'status' => 'pending',
        'action' => 'new',
    ]);
    $otherOrder->update(['latest_cert_id' => $otherCert->id]);

    // 不应触达上游
    $mockApi = Mockery::mock(Api::class);
    $mockApi->shouldNotReceive('new');
    $this->app->instance(Api::class, $mockApi);

    $this->actingAsUser($user)
        ->postJson("/api/order/commit/$otherOrder->id")
        ->assertOk()
        ->assertJson(['code' => 0]);

    // 他人订单状态不变（未被提交）
    expect($otherCert->fresh()->status)->toBe('pending')
        ->and($otherCert->fresh()->api_id)->toBeNull();
});

test('取消订单-commit-cancel：unpaid 订单委派 delete（订单+证书被删除，无资金流水）', function () {
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = Product::factory()->create();
    [$order, $cert] = createUserUnpaidOrder($user, $product, '100.00');

    $this->actingAsUser($user)
        ->postJson("/api/order/commit-cancel/$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    // unpaid 直接删除：订单 + 证书均不存在；未扣费故余额不变、无流水
    expect(Order::withoutGlobalScopes()->find($order->id))->toBeNull()
        ->and(Cert::withoutGlobalScopes()->find($cert->id))->toBeNull();
    $user->refresh();
    expect((float) $user->balance)->toBe(100.0)
        ->and(Transaction::where('transaction_id', $order->id)->count())->toBe(0);
});

test('取消订单-commit-cancel：active 订单转 cancelling + 创建延时 cancel 任务', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create(['refund_period' => 30]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->actingAsUser($user)
        ->postJson("/api/order/commit-cancel/$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    // active 走延时取消：cert 转 cancelling + 落一条 executing 的 cancel 任务（真实退费在 TaskJob 执行）
    expect($cert->fresh()->status)->toBe('cancelling');
    $cancelTask = Task::where('order_id', $order->id)
        ->where('action', 'cancel')
        ->where('status', 'executing')
        ->first();
    expect($cancelTask)->not->toBeNull();
});

test('取消订单-不能取消其他用户的订单（UserScope 越权拒绝）', function () {
    $user = $this->createTestUser();
    $otherUser = $this->createTestUser();
    $product = Product::factory()->create();
    $otherOrder = Order::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
    ]);
    $otherCert = Cert::factory()->active()->create([
        'order_id' => $otherOrder->id,
    ]);
    $otherOrder->update(['latest_cert_id' => $otherCert->id]);

    $this->actingAsUser($user)
        ->postJson("/api/order/commit-cancel/$otherOrder->id")
        ->assertOk()
        ->assertJson(['code' => 0]);

    // 越权未生效：他人订单仍 active，未生成 cancel 任务
    expect($otherCert->fresh()->status)->toBe('active')
        ->and(Task::where('order_id', $otherOrder->id)->where('action', 'cancel')->count())->toBe(0);
});

// ==================== 标记已续费（mark-renewed）====================
//
// renewed 是终态：手工标记后订单不再自动续费/到期提醒，sync 终态守卫防上游复活。
// 语义：用户另开新订单续了证书 → 把旧订单标 renewed 止住到期通知（非"原订单内重签"，
// 那个靠重签后 expires_at 推远自动止通知）。
// 这些用例真实执行 Action::markRenewed（不 mock），验证锁内二次校验：
//   - 仅 active 证书可标记；
//   - 仅【订单】到期前 30 天内且未过期可标记（按 orders.period_till，非 cert.expires_at）；
//   - UserScope 越权边界。

/**
 * 造一个 user 名下 active 证书订单，可指定订单到期时间 period_till（标记窗口校验依赖此字段）。
 * cert.expires_at 给固定合理值、刻意与 period_till 解耦 —— gate 只看订单到期、不看单证书到期。
 */
function createUserActiveOrder(User $user, Product $product, ?Carbon $periodTill = null): array
{
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => $periodTill ?? now()->addDays(25),
    ]);

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(25),
    ]);

    $order->update(['latest_cert_id' => $cert->id]);

    return [$order, $cert];
}

test('标记已续费-active + 订单到期前 25 天成功标记为 renewed', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();
    [$order, $cert] = createUserActiveOrder($user, $product, now()->addDays(25));

    $this->actingAsUser($user)
        ->postJson("/api/order/mark-renewed/$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    // 状态确为 renewed（终态）
    expect($cert->fresh()->status)->toBe('renewed');
});

test('标记已续费-active + 订单到期 40 天后被拒（超 30 天），状态不变', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();
    [$order, $cert] = createUserActiveOrder($user, $product, now()->addDays(40));

    $this->actingAsUser($user)
        ->postJson("/api/order/mark-renewed/$order->id")
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect($cert->fresh()->status)->toBe('active');
});

test('标记已续费-订单已过期被拒，状态不变', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();
    // active 证书但订单 period_till 已是过去（手工造越窗数据）
    [$order, $cert] = createUserActiveOrder($user, $product, now()->subDay());

    $this->actingAsUser($user)
        ->postJson("/api/order/mark-renewed/$order->id")
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect($cert->fresh()->status)->toBe('active');
});

test('标记已续费-非 active（pending）证书被拒，状态不变', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'pending',
        'expires_at' => now()->addDays(25),
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->actingAsUser($user)
        ->postJson("/api/order/mark-renewed/$order->id")
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect($cert->fresh()->status)->toBe('pending');
});

test('标记已续费-证书将到期但订单未到期（period_till > 30 天）被拒，状态不变', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();
    // 多年期/中途重签场景：当前证书 10 天后到期、但订单还有 200 天 —— 会被自动重签接管，
    // 不应允许标记。锁住「gate 看 orders.period_till 而非 cert.expires_at」的语义。
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays(200),
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'expires_at' => now()->addDays(10),
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->actingAsUser($user)
        ->postJson("/api/order/mark-renewed/$order->id")
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect($cert->fresh()->status)->toBe('active');
});

test('标记已续费-不能标记其他用户的订单（UserScope 越权拒绝）', function () {
    $user = $this->createTestUser();
    $otherUser = $this->createTestUser();
    $product = Product::factory()->create();
    [$otherOrder, $otherCert] = createUserActiveOrder($otherUser, $product, now()->addDays(25));

    $this->actingAsUser($user)
        ->postJson("/api/order/mark-renewed/$otherOrder->id")
        ->assertOk()
        ->assertJson(['code' => 0]);

    // 越权未生效：他人订单仍 active
    expect($otherCert->fresh()->status)->toBe('active');
});

// sendActive() 测试：路由由 GET 收紧为 POST，email 随之从 query 移到 body
test('用户发送激活邮件-email 从请求体读取', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();
    [$order] = createUserActiveOrder($user, $product);

    $captured = null;
    $mockCenter = Mockery::mock(NotificationCenter::class);
    $mockCenter->shouldReceive('dispatch')->once()
        ->andReturnUsing(function ($intent) use (&$captured) {
            $captured = $intent;
        });
    $this->app->instance(NotificationCenter::class, $mockCenter);

    $this->actingAsUser($user)
        ->postJson("/api/order/send-active/$order->id", ['email' => 'mine@example.com'])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($captured)->not->toBeNull()
        ->and($captured->notifiableId)->toBe($user->id)
        ->and($captured->context['order_id'])->toBe($order->id)
        ->and($captured->context['email'])->toBe('mine@example.com');
});

test('用户发送激活邮件-他人订单被 UserScope 隔离且不发通知', function () {
    $user = $this->createTestUser();
    $otherUser = $this->createTestUser();
    $product = Product::factory()->create();
    [$otherOrder] = createUserActiveOrder($otherUser, $product);

    $mockCenter = Mockery::mock(NotificationCenter::class);
    $mockCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $mockCenter);

    $this->actingAsUser($user)
        ->postJson("/api/order/send-active/$otherOrder->id", ['email' => 'mine@example.com'])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('用户不能为 CodeSign 或 DocSign 手工发送系统签发通知', function (string $productType) {
    $user = $this->createTestUser();
    $product = Product::factory()->create(['product_type' => $productType]);
    [$order] = createUserActiveOrder($user, $product);
    $mockCenter = Mockery::mock(NotificationCenter::class);
    $mockCenter->shouldNotReceive('dispatch');
    $this->app->instance(NotificationCenter::class, $mockCenter);

    $this->actingAsUser($user)
        ->postJson("/api/order/send-active/$order->id")
        ->assertOk()
        ->assertJson([
            'code' => 0,
            'msg' => '代码签名和文档签名不发送签发通知',
        ]);
})->with([Product::TYPE_CODESIGN, Product::TYPE_DOCSIGN]);

test('用户发送激活邮件-旧 GET 入口已下线（副作用端点不挂 GET）', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create();
    [$order] = createUserActiveOrder($user, $product);

    $this->actingAsUser($user)
        ->getJson("/api/order/send-active/$order->id")
        ->assertStatus(405);
});
