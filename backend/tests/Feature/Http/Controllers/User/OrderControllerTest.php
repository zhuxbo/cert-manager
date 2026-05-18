<?php

use App\Models\Cert;
use App\Models\Contact;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Tests\Traits\ActsAsUser;
use Tests\Traits\MocksExternalApis;

uses(ActsAsUser::class, MocksExternalApis::class);

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
        ->toBe((string) $cert->id);
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
