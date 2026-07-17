<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

// 锁定 deployCommands 在「site.url 未配置」时优雅降级的契约。
//
// 背景：deployCommands() 的 `rtrim(get_system_setting('site','url'), '/')` 在 site.url
// 未配置时收到 null。本 trait 文件无 strict_types，现况是 PHP 8 弃用告警 + 强转 ''（尚可运行），
// 但 PHP 9 该弃用将升级为 TypeError，且未来给文件补 declare(strict_types=1) 时此行即引爆
// （Sdk.php ca.url 同型 bug 已实爆，见 DefaultSdkUnconfiguredCaTest）。故 (string) 强转
// 对齐紧邻的 releaseDomain 行，并以本测试锁定未配置态的降级形态防回归。
//
// 降级形态（已实测 PHP 8.4）：$siteUrl='' → deployUrl='/api/deploy'；
// parse_url('') 的 HOST/PORT 均 null → releaseDomain=''（null 插值）、releaseUrl='/release'。
// 相对路径命令降级但结构完整、不崩。

test('site.url 未配置时 deploy-commands 返回优雅降级结果而非抛 TypeError', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->active()->create(['order_id' => $order->id]);
    $order->update(['latest_cert_id' => $cert->id]);

    // 模拟 site 组完全未配置：缓存注入空组，getByGroupName('site') 命中缓存不查 DB
    Cache::put('setting:group_name:site', [], 60);

    $response = $this->actingAsUser($user)
        ->getJson('/api/order/deploy-commands?order_ids='.$order->id)
        ->assertOk()
        ->assertJson(['code' => 1]);

    // 降级但结构完整：deployUrl 退化为相对路径 /api/deploy、releaseUrl 退化为 /release
    expect($response->json('data.deploy'))->toContain('--url /api/deploy')
        ->and($response->json('data.install.linux'))->toContain('/release/sslctl/install.sh');
});
