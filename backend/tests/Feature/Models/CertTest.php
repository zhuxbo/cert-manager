<?php

use App\Models\Cert;
use App\Models\Chain;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('证书属于订单', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create(['order_id' => $order->id]);

    expect($cert->order)->toBeInstanceOf(Order::class);
    expect($cert->order->id)->toBe($order->id);
});

test('证书状态字段值正确', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $pending = Cert::factory()->create(['order_id' => $order->id, 'status' => 'pending']);
    expect($pending->status)->toBe('pending');

    $expired = Cert::factory()->expired()->create(['order_id' => $order->id]);
    expect($expired->status)->toBe('expired');

    $revoked = Cert::factory()->revoked()->create(['order_id' => $order->id]);
    expect($revoked->status)->toBe('revoked');

    $cancelled = Cert::factory()->cancelled()->create(['order_id' => $order->id]);
    expect($cancelled->status)->toBe('cancelled');
});

test('dcv 字段为 JSON cast', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
    ]);

    $cert->refresh();
    expect($cert->dcv)->toBeArray();
    expect($cert->dcv['method'])->toBe('txt');
});

test('validation 字段为 JSON cast', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $validationData = [
        ['domain' => 'example.com', 'method' => 'txt', 'value' => 'test-value'],
    ];

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'validation' => $validationData,
    ]);

    $cert->refresh();
    expect($cert->validation)->toBeArray();
    expect($cert->validation[0]['domain'])->toBe('example.com');
});

test('日期字段正确转换', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'issuer' => null, // 避免触发中间证书查询
    ]);

    $cert->refresh();
    expect($cert->issued_at)->toBeInstanceOf(Carbon::class);
    expect($cert->expires_at)->toBeInstanceOf(Carbon::class);
});

test('创建时自动生成 csr_md5', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'csr' => 'test-csr-content',
    ]);

    expect($cert->csr_md5)->toBe(md5('test-csr-content'));
});

test('创建时自动生成 refer_id', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create(['order_id' => $order->id]);

    expect($cert->refer_id)->not->toBeNull();
    expect(strlen($cert->refer_id))->toBe(32);
});

test('amount 为 decimal:2 格式', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'amount' => '99.999',
    ]);

    $cert->refresh();
    expect($cert->amount)->toBe('100.00');
});

test('standard_count 和 wildcard_count 为整数', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'standard_count' => '3',
        'wildcard_count' => '1',
    ]);

    $cert->refresh();
    expect($cert->standard_count)->toBeInt()->toBe(3);
    expect($cert->wildcard_count)->toBeInt()->toBe(1);
});

test('证书关联上一个证书', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $firstCert = Cert::factory()->create(['order_id' => $order->id]);
    $secondCert = Cert::factory()->reissue()->create([
        'order_id' => $order->id,
        'last_cert_id' => $firstCert->id,
    ]);

    expect($secondCert->lastCert)->toBeInstanceOf(Cert::class);
    expect($secondCert->lastCert->id)->toBe($firstCert->id);
});

test('params 字段为 JSON cast', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'params' => ['key' => 'value', 'nested' => ['a' => 1]],
    ]);

    $cert->refresh();
    expect($cert->params)->toBeArray();
    expect($cert->params['key'])->toBe('value');
});

test('中间证书写入 Chain 表', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'issuer' => 'Test CA Issuer',
    ]);

    $cert->intermediate_cert = 'INTERMEDIATE CERT CONTENT';

    // 检查 Chain 表中是否存在该记录
    $chain = Chain::where('common_name', 'Test CA Issuer')->first();
    expect($chain)->not->toBeNull();
    expect($chain->intermediate_cert)->toBe('INTERMEDIATE CERT CONTENT');
});

test('批量检索 active 证书不产生 Chain N+1（请求级全表缓存，仅 1 次 chains 查询）', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    Chain::create(['common_name' => 'CA-A', 'intermediate_cert' => 'INT-A']);
    Chain::create(['common_name' => 'CA-B', 'intermediate_cert' => 'INT-B']);

    Cert::factory()->active()->count(5)->create(['order_id' => $order->id, 'issuer' => 'CA-A']);
    Cert::factory()->active()->count(5)->create(['order_id' => $order->id, 'issuer' => 'CA-B']);

    app()->forgetInstance('cert.chainMap'); // 清请求级缓存，计数从干净开始

    $chainQueries = 0;
    DB::listen(function ($q) use (&$chainQueries) {
        if (str_contains($q->sql, 'chains')) {
            $chainQueries++;
        }
    });

    // 检索 10 条 active cert 并访问 intermediate_cert（触发 retrieved + accessor）
    Cert::where('order_id', $order->id)->get()->each(fn ($c) => $c->intermediate_cert);

    // 全表缓存：≤ 1 次 chains 查询（逐条查会是 10 次以上）
    expect($chainQueries)->toBeLessThanOrEqual(1);
});

test('active 证书中间证书缺失时检索后 status 改 approving（保留有意设计）', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    // issuer 无对应 Chain 记录
    $cert = Cert::factory()->active()->create(['order_id' => $order->id, 'issuer' => 'CA-MISSING']);
    app()->forgetInstance('cert.chainMap');

    $retrieved = Cert::find($cert->id);
    expect($retrieved->status)->toBe('approving');
});

test('active 证书中间证书存在时 status 保持 active 且 intermediate_cert 正确', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    Chain::create(['common_name' => 'CA-OK', 'intermediate_cert' => 'INT-OK']);
    $cert = Cert::factory()->active()->create(['order_id' => $order->id, 'issuer' => 'CA-OK']);
    app()->forgetInstance('cert.chainMap');

    $retrieved = Cert::find($cert->id);
    expect($retrieved->status)->toBe('active')
        ->and($retrieved->intermediate_cert)->toBe('INT-OK');
});

test('写入新中间证书后请求级缓存失效（同请求后续检索读到新值）', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    $cert = Cert::factory()->active()->create(['order_id' => $order->id, 'issuer' => 'CA-LATE']);
    app()->forgetInstance('cert.chainMap');

    // 首次检索：CA-LATE 无 Chain → intermediate null + status approving，且 chainMap 已缓存
    $before = Cert::find($cert->id);
    expect($before->intermediate_cert)->toBeNull()
        ->and($before->status)->toBe('approving');

    // 通过 setIntermediateCert 写入 Chain（内部 forgetInstance 让缓存失效）
    $writer = Cert::factory()->active()->create(['order_id' => $order->id, 'issuer' => 'CA-LATE']);
    $writer->intermediate_cert = 'INT-LATE';

    // 再次检索：缓存已失效 → 读到新值 + status 回 active
    $after = Cert::find($cert->id);
    expect($after->intermediate_cert)->toBe('INT-LATE')
        ->and($after->status)->toBe('active');
});
